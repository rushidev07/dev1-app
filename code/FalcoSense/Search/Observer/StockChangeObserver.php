<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class StockChangeObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                      $helper,
        private readonly ProductSyncService        $syncService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory         $collectionFactory,
        private readonly LoggerInterface           $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\CatalogInventory\Model\Stock\Item $stockItem */
        $stockItem = $observer->getEvent()->getItem();

        if (!$stockItem || !$stockItem->getProductId()) {
            $this->logger->debug('[SmartSearch][RT] StockChangeObserver fired but stock item/product ID missing — skipping.');
            return;
        }

        $origQty     = (float) $stockItem->getOrigData('qty');
        $currQty     = (float) $stockItem->getQty();
        $origInStock = (int) $stockItem->getOrigData('is_in_stock');
        $currInStock = (int) $stockItem->getIsInStock();

        $this->logger->info(sprintf(
            '[SmartSearch][RT] StockChangeObserver fired: product_id=%d qty=%s->%s in_stock=%s->%s',
            (int) $stockItem->getProductId(), $origQty, $currQty, $origInStock, $currInStock
        ));

        if ($origQty === $currQty && $origInStock === $currInStock) {
            $this->logger->info('[SmartSearch][RT] No stock delta for product ' . (int) $stockItem->getProductId() . ' — skipping.');
            return;
        }

        $productId = (int) $stockItem->getProductId();

        $this->logger->info(sprintf(
            '[SmartSearch][RT] Stock changed for product %d (qty: %s->%s, in_stock: %s->%s) — syncing.',
            $productId, $origQty, $currQty, $origInStock, $currInStock
        ));

        try {
            // Load fresh product, pass the event's stock item directly so normalize()
            // doesn't read the stale in-memory StockRegistry cache (still shows old value).
            $product = $this->productRepository->getById($productId, false, null, true);

            // Sync once per store the product is actually assigned to — a stock item
            // change isn't store-scoped by itself, but which platform client/API key
            // it must be pushed under (and deleted from, if now OOS) is. Falling back
            // to a single store-0 call (as before) would silently push/delete against
            // whichever store happens to resolve the default-scope config, which is
            // wrong for any multi-store install.
            $storeIds = $this->resolveStoreIds($product);

            foreach ($storeIds as $storeId) {
                if (!$this->helper->isEnabled($storeId)) {
                    continue;
                }
                if (!$this->helper->isRealtimeSyncEnabled($storeId)) {
                    $this->logger->info(sprintf('[SmartSearch][RT] Real-time sync disabled for store %d — skipping (cron will sync).', $storeId));
                    continue;
                }
                $this->syncService->sync($product, $storeId, $stockItem);
            }

            // Always check for a parent — getVisibility() may not be loaded on the product object.
            $this->syncParentConfigurable($productId, $storeIds);
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch] StockChangeObserver failed for product ' . $productId . ': ' . $e->getMessage());
        }
    }

    /**
     * Store view IDs this product is actually assigned to (via its website(s)).
     * Falls back to [0] (default scope) if the product resolves none — e.g. a
     * product not yet assigned to any website — to preserve prior behavior.
     *
     * @return int[]
     */
    private function resolveStoreIds(\Magento\Catalog\Model\Product $product): array
    {
        $storeIds = array_map('intval', $product->getStoreIds() ?: []);
        return !empty($storeIds) ? $storeIds : [0];
    }

    /**
     * @param int[] $storeIds
     */
    private function syncParentConfigurable(int $childId, array $storeIds): void
    {
        try {
            $parents = $this->collectionFactory->create();
            $parents->addAttributeToSelect('*');
            $parents->joinField(
                'child_id',
                'catalog_product_relation',
                'child_id',
                'parent_id=entity_id',
                ['child_id' => $childId]
            );
            foreach ($parents as $parent) {
                if ((int) $parent->getId() === $childId) {
                    continue; // guard against corrupt relation rows where parent_id = child_id
                }
                $parentStoreIds = $this->resolveStoreIds($parent) ?: $storeIds;
                foreach ($parentStoreIds as $storeId) {
                    if (!$this->helper->isEnabled($storeId) || !$this->helper->isRealtimeSyncEnabled($storeId)) {
                        continue;
                    }
                    $this->logger->info(sprintf(
                        '[SmartSearch][RT] Re-syncing parent configurable %d (store %d) after stock change on child %d.',
                        $parent->getId(), $storeId, $childId
                    ));
                    $this->syncService->sync($parent, $storeId);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearch][RT] Could not sync parent for child ' . $childId . ': ' . $e->getMessage());
        }
    }
}
