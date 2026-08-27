<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Controller\Adminhtml\Run;

use Ahy\DiscountAlert\Api\RunRepositoryInterface;
use Ahy\DiscountAlert\Block\Adminhtml\Run\Items;
use Ahy\DiscountAlert\Service\Catalog\SpecialPriceUpdater;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Shared plumbing for the two price actions on the alert review grid.
 *
 * Mirrors the safety model of the Disable action deliberately:
 *  - POST only, so form-key and admin secret-key validation both apply and no link
 *    follower (a mail scanner, a prefetching browser) can trigger it;
 *  - the run token is validated before anything is touched;
 *  - submitted IDs are intersected with the run's own products, so a tampered POST
 *    cannot reach a product this run never flagged;
 *  - a single-row click wins over the checkbox selection, so acting on one row never
 *    sweeps up whatever else happened to be ticked.
 *
 * Disable was left as it stands rather than refactored onto this base: it is in daily use
 * and its behaviour is not changing here.
 */
abstract class AbstractPriceAction extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ahy_DiscountAlert::manage';

    /**
     * SKUs listed in the success message before it falls back to "and N more", so a large
     * mass action does not produce an unreadable wall of text.
     */
    private const MAX_LISTED_SKUS = 10;

    /**
     * Grid state carried through the redirect so the user lands back where they were.
     */
    private const STATE_PARAMS = [
        Items::PARAM_PAGE,
        Items::PARAM_LIMIT,
        Items::PARAM_SORT,
        Items::PARAM_DIR,
        Items::PARAM_SKU,
        Items::PARAM_NAME,
        Items::PARAM_STATUS,
        Items::PARAM_PRICE_STATE,
        Items::PARAM_DISCOUNT_FROM,
        Items::PARAM_DISCOUNT_TO,
        Items::PARAM_SHOW_FILTERS,
    ];

    public function __construct(
        Context $context,
        protected readonly RunRepositoryInterface $runRepository,
        protected readonly SpecialPriceUpdater $priceUpdater,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Apply the action to products already validated as belonging to this run.
     *
     * @param int[] $productIds
     */
    abstract protected function applyTo(int $runId, array $productIds, ?int $adminUserId): void;

    /**
     * Verb used in log lines and in the "N products were ..." confirmation, e.g.
     * "reverted to MSRP".
     */
    abstract protected function getPastTenseVerb(): string;

    /**
     * Short name for the log line that records the click arriving.
     */
    abstract protected function getActionName(): string;

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $runId    = (int) $this->getRequest()->getParam('run_id');
        $token    = (string) $this->getRequest()->getParam('token');

        // Logged first, so the log answers "did the click reach the server at all?"
        $this->logger->info(\sprintf(
            '%s action reached: run_id=%d, single_product_id=%s, selected=%d',
            $this->getActionName(),
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
        $allowed  = $this->runRepository->getProductIds($runId);
        $targets  = \array_values(\array_intersect($requested, $allowed));
        $rejected = \count($requested) - \count($targets);

        if ($rejected > 0) {
            $this->logger->warning(\sprintf(
                'Run #%d: %d submitted product ID(s) were not part of the run and were ignored.',
                $runId,
                $rejected
            ));
        }

        if (!$targets) {
            $this->messageManager->addErrorMessage(__('None of the selected products belong to this alert run.'));

            return $redirect->setPath('*/*/view', $backParams);
        }

        $labels      = $this->runRepository->getProductLabels($runId, $targets);
        $adminUserId = $this->getAdminUserId();

        try {
            $this->applyTo($runId, $targets, $adminUserId);

            $this->messageManager->addSuccessMessage($this->buildSuccessMessage($targets, $labels));

            $this->logger->info(\sprintf(
                'Run #%d: admin user %s %s %d product(s): %s',
                $runId,
                (string) $adminUserId,
                $this->getPastTenseVerb(),
                \count($targets),
                \implode(', ', $this->describeAll($targets, $labels))
            ));
        } catch (\Throwable $e) {
            $this->logger->error(
                \sprintf('Discount alert %s action failed: %s', $this->getActionName(), $e->getMessage()),
                ['exception' => $e]
            );
            $this->messageManager->addErrorMessage(
                __('Could not update the selected products: %1', $e->getMessage())
            );
        }

        return $redirect->setPath('*/*/view', $backParams);
    }

    /**
     * Names what was acted on, so the confirmation is specific rather than just a count.
     *
     * @param int[]                                            $productIds
     * @param array<int, array{sku: string, name: string|null}> $labels
     */
    private function buildSuccessMessage(array $productIds, array $labels): Phrase
    {
        $verb = $this->getPastTenseVerb();

        if (\count($productIds) === 1) {
            $productId = (int) \reset($productIds);
            $label     = $labels[$productId] ?? null;

            if ($label === null) {
                return __('The product was %1.', $verb);
            }

            return $label['name'] !== null && $label['name'] !== ''
                ? __('"%1" (SKU %2) was %3.', $label['name'], $label['sku'], $verb)
                : __('SKU %1 was %2.', $label['sku'], $verb);
        }

        $skus      = $this->collectSkus($productIds, $labels);
        $listed    = \array_slice($skus, 0, self::MAX_LISTED_SKUS);
        $remaining = \count($skus) - \count($listed);

        if (!$listed) {
            return __('%1 products were %2.', \count($productIds), $verb);
        }

        return $remaining > 0
            ? __(
                '%1 products were %2: %3 and %4 more.',
                \count($productIds),
                $verb,
                \implode(', ', $listed),
                $remaining
            )
            : __('%1 products were %2: %3.', \count($productIds), $verb, \implode(', ', $listed));
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
     * A single-row action wins over the checkbox selection, so clicking one row's button
     * never sweeps up whatever else happened to be ticked.
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
