<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class FullSyncPublisher
{
    public const TOPIC = 'falcosense.search.full_sync';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Publish one message containing a batch of product IDs to the full-sync queue.
     *
     * @param int[] $productIds
     */
    public function publishBatch(array $productIds, int $storeId = 0): void
    {
        $message = json_encode(['product_ids' => $productIds, 'store_id' => $storeId]);
        $this->publisher->publish(self::TOPIC, $message);

        $this->logger->info(sprintf(
            '[SmartSearch][FullSyncPublisher] Published batch of %d product IDs (store %d).',
            count($productIds),
            $storeId
        ));
    }
}
