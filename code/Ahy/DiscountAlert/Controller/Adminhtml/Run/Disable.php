<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Controller\Adminhtml\Run;

use Ahy\DiscountAlert\Api\RunRepositoryInterface;
use Ahy\DiscountAlert\Block\Adminhtml\Run\Items;
use Ahy\DiscountAlert\Service\Catalog\ProductStatusUpdater;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Disables products chosen on the alert review grid, either a single row or a selection.
 *
 * POST only, so form-key and admin secret-key validation both apply and no link follower
 * (a mail scanner, a prefetching browser) can trigger it.
 */
class Disable extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ahy_DiscountAlert::manage';

    /**
     * Grid state carried through the redirect so the user lands back where they were.
     */
    /**
     * SKUs listed in the success message before it falls back to "and N more", so a large
     * mass action does not produce an unreadable wall of text.
     */
    private const MAX_LISTED_SKUS = 10;

    private const STATE_PARAMS = [
        Items::PARAM_PAGE,
        Items::PARAM_LIMIT,
        Items::PARAM_SORT,
        Items::PARAM_DIR,
        Items::PARAM_SKU,
        Items::PARAM_NAME,
        Items::PARAM_STATUS,
        Items::PARAM_DISCOUNT_FROM,
        Items::PARAM_DISCOUNT_TO,
        Items::PARAM_SHOW_FILTERS,
    ];

    public function __construct(
        Context $context,
        private readonly RunRepositoryInterface $runRepository,
        private readonly ProductStatusUpdater $statusUpdater,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $runId    = (int) $this->getRequest()->getParam('run_id');
        $token    = (string) $this->getRequest()->getParam('token');

        // Logged first, so the log answers "did the click reach the server at all?"
        $this->logger->info(\sprintf(
            'Disable action reached: run_id=%d, single_product_id=%s, selected=%d',
            $runId,
            (string) $this->getRequest()->getParam(Items::FIELD_SINGLE_PRODUCT_ID, '(none)'),
            \count((array) $this->getRequest()->getParam(Items::FIELD_PRODUCT_IDS, []))
        ));

        try {
            $this->runRepository->getByToken($runId, $token);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('adminhtml/dashboard');
        }

        $backParams = $this->getBackParams($runId, $token);
        $requested  = $this->getRequestedProductIds();

        if (!$requested) {
            $this->messageManager->addNoticeMessage(__('No products were selected, so nothing was changed.'));

            return $redirect->setPath('*/*/view', $backParams);
        }

        // Never touch anything outside the run this link belongs to.
        $allowed   = $this->runRepository->getProductIds($runId);
        $toDisable = \array_values(\array_intersect($requested, $allowed));
        $rejected  = \count($requested) - \count($toDisable);

        if ($rejected > 0) {
            $this->logger->warning(\sprintf(
                'Run #%d: %d submitted product ID(s) were not part of the run and were ignored.',
                $runId,
                $rejected
            ));
        }

        if (!$toDisable) {
            $this->messageManager->addErrorMessage(__('None of the selected products belong to this alert run.'));

            return $redirect->setPath('*/*/view', $backParams);
        }

        $labels = $this->runRepository->getProductLabels($runId, $toDisable);

        try {
            $this->statusUpdater->disable($toDisable);
            $this->runRepository->markItemsDisabled($runId, $toDisable, $this->getAdminUserId());

            $this->messageManager->addSuccessMessage($this->buildSuccessMessage($toDisable, $labels));

            $this->logger->info(\sprintf(
                'Run #%d: admin user %s disabled %d product(s): %s',
                $runId,
                (string) $this->getAdminUserId(),
                \count($toDisable),
                \implode(', ', $this->describeAll($toDisable, $labels))
            ));
        } catch (\Throwable $e) {
            $this->logger->error(
                'Discount alert disable action failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->messageManager->addErrorMessage(
                __('Could not disable the selected products: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/view', $backParams);
    }

    /**
     * Names what was disabled, so the confirmation is specific rather than just a count.
     *
     * @param int[]                                            $productIds
     * @param array<int, array{sku: string, name: string|null}> $labels
     */
    private function buildSuccessMessage(array $productIds, array $labels): Phrase
    {
        if (\count($productIds) === 1) {
            $productId = (int) \reset($productIds);
            $label     = $labels[$productId] ?? null;

            if ($label === null) {
                return __('The product was disabled.');
            }

            return $label['name'] !== null && $label['name'] !== ''
                ? __('"%1" (SKU %2) was disabled.', $label['name'], $label['sku'])
                : __('SKU %1 was disabled.', $label['sku']);
        }

        $skus      = $this->collectSkus($productIds, $labels);
        $listed    = \array_slice($skus, 0, self::MAX_LISTED_SKUS);
        $remaining = \count($skus) - \count($listed);

        if (!$listed) {
            return __('%1 products were disabled.', \count($productIds));
        }

        return $remaining > 0
            ? __(
                '%1 products were disabled: %2 and %3 more.',
                \count($productIds),
                \implode(', ', $listed),
                $remaining
            )
            : __('%1 products were disabled: %2.', \count($productIds), \implode(', ', $listed));
    }

    /**
     * @param  int[]                                            $productIds
     * @param  array<int, array{sku: string, name: string|null}> $labels
     * @return string[]
     */
    private function collectSkus(array $productIds, array $labels): array
    {
        $skus = [];

        foreach ($productIds as $productId) {
            if (isset($labels[(int) $productId])) {
                $skus[] = $labels[(int) $productId]['sku'];
            }
        }

        return $skus;
    }

    /**
     * "SKU (name)" per product, for the log line.
     *
     * @param  int[]                                            $productIds
     * @param  array<int, array{sku: string, name: string|null}> $labels
     * @return string[]
     */
    private function describeAll(array $productIds, array $labels): array
    {
        $described = [];

        foreach ($productIds as $productId) {
            $label = $labels[(int) $productId] ?? null;

            if ($label === null) {
                $described[] = (string) $productId;
                continue;
            }

            $described[] = $label['name'] !== null && $label['name'] !== ''
                ? \sprintf('%s (%s)', $label['sku'], $label['name'])
                : $label['sku'];
        }

        return $described;
    }

    /**
     * A single-row action wins over the checkbox selection, so clicking one row's Disable
     * button never sweeps up whatever else happened to be ticked.
     *
     * @return int[]
     */
    private function getRequestedProductIds(): array
    {
        $single = (int) $this->getRequest()->getParam(Items::FIELD_SINGLE_PRODUCT_ID);

        if ($single > 0) {
            return [$single];
        }

        $raw = (array) $this->getRequest()->getParam(Items::FIELD_PRODUCT_IDS, []);

        return \array_values(\array_unique(\array_filter(\array_map('intval', $raw))));
    }

    /**
     * @return array<string, mixed>
     */
    private function getBackParams(int $runId, string $token): array
    {
        $params = ['run_id' => $runId, 'token' => $token];

        foreach (self::STATE_PARAMS as $param) {
            $value = $this->getRequest()->getParam($param);

            if ($value !== null && $value !== '') {
                $params[$param] = $value;
            }
        }

        return $params;
    }

    private function getAdminUserId(): ?int
    {
        $user = $this->_auth->getUser();

        return $user ? (int) $user->getId() : null;
    }
}
