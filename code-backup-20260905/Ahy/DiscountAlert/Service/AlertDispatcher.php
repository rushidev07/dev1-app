<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service;

use Ahy\DiscountAlert\Api\ConfigInterface;
use Ahy\DiscountAlert\Api\Data\RunInterface;
use Ahy\DiscountAlert\Api\ProductCollectorInterface;
use Ahy\DiscountAlert\Api\RunRepositoryInterface;
use Ahy\DiscountAlert\Service\Email\ReviewUrlBuilder;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Single entry point for producing a discount alert, shared by the cron job and the CLI
 * command so both behave identically.
 */
class AlertDispatcher
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ProductCollectorInterface $collector,
        private readonly RunRepositoryInterface $runRepository,
        private readonly DiscountAlertSender $sender,
        private readonly ReviewUrlBuilder $reviewUrlBuilder,
        private readonly AlertResultFactory $resultFactory,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Collect, record and send. Every argument overrides the stored configuration and is
     * intended for the CLI; cron passes none.
     *
     * @param  string[]|null $ccEmails
     * @param  string[]|null $bccEmails
     * @param  bool          $force Send even when nothing has changed since the previous run.
     * @throws LocalizedException When collection fails, or sending fails outside cron.
     */
    public function dispatch(
        ?float $threshold = null,
        ?string $recipient = null,
        ?array $ccEmails = null,
        ?array $bccEmails = null,
        ?int $limit = null,
        bool $dryRun = false,
        bool $force = false
    ): AlertResult {
        $threshold = $threshold ?? $this->config->getThreshold();
        $recipient = $recipient ?? $this->config->getRecipientEmail();
        $limit     = $limit ?? $this->config->getMaxProducts();

        if (!$dryRun && $recipient === '') {
            return $this->skip(
                __('No recipient email is configured.')->render(),
                $threshold
            );
        }

        $matchedTotal = $this->collector->count($threshold);

        if ($matchedTotal === 0) {
            return $this->skip(
                __('No products exceed the %1% discount threshold.', $threshold)->render(),
                $threshold
            );
        }

        $products = $this->collector->getList($threshold, $limit);

        if ($limit > 0 && $matchedTotal > $limit) {
            $this->logger->warning(\sprintf(
                'Discount alert capped at %d of %d matching products. Raise the maximum '
                . 'under Stores > Configuration > Ahy > Discount Alert to include more.',
                $limit,
                $matchedTotal
            ));
        }

        if ($dryRun) {
            return $this->resultFactory->create([
                'status'         => AlertResult::STATUS_DRY_RUN,
                'threshold'      => $threshold,
                'matchedTotal'   => $matchedTotal,
                'collectedCount' => \count($products),
                'recipient'      => $recipient,
                'products'       => $products,
            ]);
        }

        // The run is always recorded, so the schedule is auditable and the next comparison
        // is against what the catalog actually looked like this time.
        $run   = $this->runRepository->create($products, $threshold, $matchedTotal);
        $runId = (int) $run->getRunId();

        if (!$force && $this->config->isNotifyOnChangeOnly() && !$run->hasChanges()) {
            return $this->resultFactory->create([
                'status'         => AlertResult::STATUS_NO_CHANGE,
                'threshold'      => $threshold,
                'matchedTotal'   => $matchedTotal,
                'collectedCount' => \count($products),
                'recipient'      => $recipient,
                'runId'          => $runId,
                'reason'         => __('No products were added or removed since the previous run.')->render(),
                'products'       => $products,
            ]);
        }

        $reviewUrl = $this->buildReviewUrl($run);

        $this->sender->send(
            $recipient,
            $products,
            $threshold,
            $matchedTotal,
            $ccEmails ?? $this->config->getCcEmails(),
            $bccEmails ?? $this->config->getBccEmails(),
            $reviewUrl,
            $this->runRepository->getNewProductIds($runId)
        );

        $this->runRepository->markEmailSent($runId);

        return $this->resultFactory->create([
            'status'         => AlertResult::STATUS_SENT,
            'threshold'      => $threshold,
            'matchedTotal'   => $matchedTotal,
            'collectedCount' => \count($products),
            'recipient'      => $recipient,
            'runId'          => $runId,
            'reviewUrl'      => $reviewUrl,
            'products'       => $products,
            'newCount'       => $run->getNewCount(),
            'removedCount'   => $run->getRemovedCount(),
        ]);
    }

    /**
     * A missing review link must never cost us the alert itself.
     */
    private function buildReviewUrl(RunInterface $run): string
    {
        try {
            return $this->reviewUrlBuilder->build($run);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Could not build the discount alert review link: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return '';
        }
    }

    private function skip(string $reason, float $threshold): AlertResult
    {
        return $this->resultFactory->create([
            'status'    => AlertResult::STATUS_SKIPPED,
            'threshold' => $threshold,
            'reason'    => $reason,
        ]);
    }
}
