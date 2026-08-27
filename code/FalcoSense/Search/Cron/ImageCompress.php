<?php
declare(strict_types=1);

namespace FalcoSense\Search\Cron;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\ProductImageCompressionService;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Delta image-compress — runs on its own schedule (separate from the
 * product-sync cron), compressing only products changed since the last
 * fully-drained run, newest-updated first. See
 * ProductImageCompressionService::run() for why the cursor only advances
 * on a non-truncated run.
 */
class ImageCompress
{
    private const LOCK_NAME    = 'falcosense_search_image_compress';
    private const LOCK_TIMEOUT = 3600; // 1 hour — generous ceiling for a slow GD batch, never held indefinitely

    public function __construct(
        private readonly Data                            $helper,
        private readonly ProductImageCompressionService  $service,
        private readonly LockManagerInterface             $lockManager,
        private readonly LoggerInterface                  $logger,
    ) {
    }

    public function execute(): void
    {
        if (!$this->helper->isImageCompressEnabled()) {
            return;
        }

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT)) {
            $this->logger->warning('[SmartSearch][ImageCompress] Already running elsewhere — skipping this tick.');
            return;
        }

        try {
            $since = $this->helper->getImageCompressLastRunAt();
            $limit = $this->helper->getImageCompressBatchSize();

            $stats = $this->service->run($since, $limit);

            $this->logger->info(sprintf(
                '[SmartSearch][ImageCompress] checked=%d compressed=%d skipped=%d missing=%d failed=%d truncated=%s since=%s',
                $stats['checked'], $stats['compressed'], $stats['skipped'], $stats['missing'], $stats['failed'],
                $stats['truncated'] ? 'yes' : 'no', $since ?? 'none'
            ));

            // Only advance the cursor once the backlog is fully drained — see
            // ProductImageCompressionService::run() docblock for why.
            if (!$stats['truncated'] && $stats['newestUpdatedAt'] !== null) {
                $this->helper->setImageCompressLastRunAt($stats['newestUpdatedAt']);
            } elseif ($stats['truncated']) {
                $this->logger->warning('[SmartSearch][ImageCompress] Backlog larger than batch size — cursor not advanced, will continue next run.');
            }
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
