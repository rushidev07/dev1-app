<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Cron;

use Ahy\DiscountAlert\Api\ConfigInterface;
use Ahy\DiscountAlert\Service\AlertDispatcher;
use Ahy\DiscountAlert\Service\AlertResult;
use Psr\Log\LoggerInterface;

/**
 * Scheduled entry point. All logic lives in AlertDispatcher so the CLI command behaves
 * identically; this only guards the enabled flag and turns the outcome into log lines.
 */
class SendDiscountAlert
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly AlertDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $result = $this->dispatcher->dispatch();
        } catch (\Throwable $e) {
            // Catch Throwable, not Exception: a PHP Error here would otherwise abort the
            // whole cron group rather than just this job.
            $this->logger->error(
                'Discount alert run failed: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return;
        }

        $this->log($result);
    }

    private function log(AlertResult $result): void
    {
        if ($result->getStatus() === AlertResult::STATUS_SKIPPED) {
            $this->logger->info('Discount alert skipped: ' . $result->getReason());

            return;
        }

        if ($result->getStatus() === AlertResult::STATUS_NO_CHANGE) {
            $this->logger->info(\sprintf(
                'Discount alert run #%d recorded, no email sent: %s',
                (int) $result->getRunId(),
                $result->getReason()
            ));

            return;
        }

        $this->logger->info(\sprintf(
            'Discount alert sent to %s, run #%d, %d of %d product(s) above the %s%% threshold.',
            $result->getRecipient(),
            (int) $result->getRunId(),
            $result->getCollectedCount(),
            $result->getMatchedTotal(),
            \rtrim(\rtrim(\number_format($result->getThreshold(), 2, '.', ''), '0'), '.')
        ));

        $this->logger->info(\sprintf(
            'Run #%d change summary: %d newly flagged, %d no longer flagged.',
            (int) $result->getRunId(),
            $result->getNewCount(),
            $result->getRemovedCount()
        ));
    }
}
