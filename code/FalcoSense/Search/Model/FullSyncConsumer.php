<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model;

use FalcoSense\Search\Service\ProductSyncService;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Psr\Log\LoggerInterface;

class FullSyncConsumer
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductSyncService $syncService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Consume one batch message: load the product objects and POST them to the platform.
     * Message format: {"product_ids": [1,2,3,...], "store_id": 0}
     * Throwing here signals the queue framework to retry.
     */
    public function process(string $message): void
    {
        $data       = json_decode($message, true);
        $productIds = $data['product_ids'] ?? [];
        $storeId    = (int) ($data['store_id'] ?? 0);

        if (empty($productIds)) {
            $this->logger->warning('[SmartSearch][FullSyncConsumer] Received empty product_ids — skipping.');
            return;
        }

        $this->logger->info(sprintf(
            '[SmartSearch][FullSyncConsumer] Processing batch of %d products (store %d).',
            count($productIds),
            $storeId
        ));

        // Load full product objects in one collection query (not N getById calls).
        // '*' loads all EAV attributes so normalize() can iterate getAttributes()
        // and send every attribute to the platform — required for dynamic filter discovery.
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('entity_id', ['in' => $productIds]);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        if ($storeId > 0) {
            $collection->setStoreId($storeId);
        }

        $products = array_values($collection->getItems());

        if (empty($products)) {
            $this->logger->info('[SmartSearch][FullSyncConsumer] No enabled products found in batch — skipping.');
            return;
        }

        $synced = $this->syncService->syncBatch($products, $storeId);

        if ($synced === -1) {
            throw new \RuntimeException(
                '[SmartSearch][FullSyncConsumer] syncBatch failed (config/connection error) — will retry.'
            );
        }

        $this->logger->info(sprintf(
            '[SmartSearch][FullSyncConsumer] Batch complete. synced=%d of %d loaded.',
            $synced,
            count($products)
        ));
    }
}
