<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model;

use FalcoSense\Search\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

class WebhookConsumer
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductSyncService         $syncService,
        private readonly LoggerInterface            $logger,
    ) {}

    /**
     * Called by Magento's queue consumer for every message on falcosense.search.product.sync.
     * Message is a JSON string: {"product_id": 123, "store_id": 1}
     * Loads the full product fresh from DB so data is never stale, then POSTs to platform.
     * Throwing here tells the queue framework to retry.
     */
    public function process(string $message): void
    {
        $data      = json_decode($message, true);
        $productId = (int) ($data['product_id'] ?? 0);
        $storeId   = (int) ($data['store_id'] ?? 0);

        $this->logger->info(sprintf(
            '[SmartSearch][Consumer] Processing product %d (store %d).',
            $productId,
            $storeId
        ));

        try {
            $product = $this->productRepository->getById(
                $productId,
                false,
                $storeId ?: null,
                true  // forceReload — bypass repository cache to get fresh data
            );

            $success = $this->syncService->sync($product, $storeId);

            if (!$success) {
                // Throwing causes the queue framework to mark the message as failed / retry
                throw new \RuntimeException(
                    '[SmartSearch][Consumer] Sync failed for product ' . $productId . ' — will retry.'
                );
            }

            $this->logger->info('[SmartSearch][Consumer] Product ' . $productId . ' synced successfully.');
        } catch (\RuntimeException $e) {
            $this->logger->warning($e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch][Consumer] Unexpected error for product ' . $productId . ': ' . $e->getMessage());
            throw $e;
        }
    }
}
