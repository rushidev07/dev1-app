<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Model\AttributeChangeDetector;
use FalcoSense\Search\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductSaveObserver implements ObserverInterface
{
    /**
     * Max real-time syncs per minute from observer.
     * Above this, the cron delta sync will catch up within 1 minute.
     * Prevents hammering the platform during bulk imports.
     */
    private const RATE_LIMIT_PER_MINUTE = 120;
    private const CACHE_KEY = 'smartsearch_observer_rate_';

    public function __construct(
        private readonly Data                    $helper,
        private readonly AttributeChangeDetector $changeDetector,
        private readonly ProductSyncService      $syncService,
        private readonly FrontendInterface       $cache,
        private readonly LoggerInterface         $logger,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory       $collectionFactory,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();

        if (!$product || !$product->getId()) {
            $this->logger->debug('[SmartSearch][RT] ProductSaveObserver fired but product is null/no ID — skipping.');
            return;
        }

        $storeId = (int) $product->getStoreId();
        $this->logger->info(sprintf('[SmartSearch][RT] ProductSaveObserver fired: product_id=%d sku=%s store=%d', $product->getId(), $product->getSku(), $storeId));

        if (!$this->helper->isEnabled($storeId)) {
            $this->logger->info('[SmartSearch][RT] Module disabled for store ' . $storeId . ' — skipping.');
            return;
        }

        if (!$this->helper->isRealtimeSyncEnabled($storeId)) {
            $this->logger->info('[SmartSearch][RT] Real-time sync disabled for store ' . $storeId . ' — skipping (cron will sync).');
            return;
        }

        if (!$this->isWithinRateLimit($storeId)) {
            $this->logger->warning(sprintf(
                '[SmartSearch][RT] Rate limit reached (%d/min) — product %d will sync via cron.',
                self::RATE_LIMIT_PER_MINUTE,
                $product->getId()
            ));
            return;
        }

        $stockDataPresent = is_array($product->getData('stock_data'));
        $hasChange        = $this->changeDetector->hasRelevantChange($product);
        $this->logger->debug(sprintf('[SmartSearch][RT] product %d — stock_data_present=%s has_relevant_change=%s', $product->getId(), $stockDataPresent ? 'true' : 'false', $hasChange ? 'true' : 'false'));

        if (!$stockDataPresent && !$hasChange) {
            $this->logger->info('[SmartSearch][RT] No relevant change for product ' . $product->getId() . ' — skipping.');
            return;
        }

        $this->logger->info(sprintf('[SmartSearch][RT] Dispatching sync for product %d (%s).', $product->getId(), $product->getSku()));

        $result = $this->syncService->sync($product, $storeId);
        $this->logger->info(sprintf('[SmartSearch][RT] sync() returned %s for product %d.', $result ? 'true' : 'false', $product->getId()));

        // If this is a variant child, also re-sync the parent configurable so its
        // variants[] array and pricing_inventory.price stay accurate in OpenSearch.
        // Check catalog_product_relation directly — getVisibility() may not be loaded
        // on the observer product object, causing the parent re-sync to be silently skipped.
        $this->syncParentConfigurable((int) $product->getId(), $storeId);
    }

    private function syncParentConfigurable(int $childId, int $storeId): void
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

            // No rows means this product is not a variant child — nothing to do.
            if ($parents->getSize() === 0) {
                return;
            }

            foreach ($parents as $parent) {
                // Guard against corrupt relation rows where parent_id = child_id
                if ((int) $parent->getId() === $childId) {
                    continue;
                }
                $this->logger->info(sprintf(
                    '[SmartSearch][RT] Re-syncing parent configurable %d for updated child %d.',
                    $parent->getId(), $childId
                ));
                $this->syncService->sync($parent, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearch][RT] Could not sync parent for child ' . $childId . ': ' . $e->getMessage());
        }
    }

    private function isWithinRateLimit(int $storeId): bool
    {
        $bucket  = date('YmdHi'); // changes every minute
        $cacheKey = self::CACHE_KEY . $storeId . '_' . $bucket;

        $current = (int) ($this->cache->load($cacheKey) ?: 0);

        if ($current >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        // Increment counter, expire at end of current minute + 5s buffer
        $ttl = 65 - (int) date('s');
        $this->cache->save((string) ($current + 1), $cacheKey, [], $ttl);

        return true;
    }
}
