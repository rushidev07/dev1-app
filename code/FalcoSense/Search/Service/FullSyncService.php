<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Model\FullSyncPublisher;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Psr\Log\LoggerInterface;

class FullSyncService
{
    private const BATCH_SIZE = 500;

    // Products priced above this are excluded from sync — placeholder/test pricing
    // (e.g. 99999) has repeatedly polluted the platform index and required manual cleanup.
    // For configurables, this is checked against each CHILD's own price
    // (see DisabledParentResolver::getConfigurableParentIdsWithOverpricedChild),
    // not the parent's own price attribute, which Magento often leaves null/unreliable.
    private const MAX_SYNC_PRICE = 90000.0;

    public function __construct(
        private readonly CollectionFactory     $collectionFactory,
        private readonly FullSyncPublisher     $publisher,
        private readonly Data                  $helper,
        private readonly LoggerInterface       $logger,
        private readonly DisabledParentResolver $disabledParentResolver,
        private readonly ProductSyncService    $syncService,
    ) {}

    /**
     * Load every enabled, in-stock, non-superseded product ID priced at or
     * below MAX_SYNC_PRICE (checked per-variant for configurables) in pages
     * of 500, publish each page as one queue message.
     * Returns ['products' => int, 'batches' => int].
     */
    public function queueAll(int $magentoStoreId = 0): array
    {
        $page         = 1;
        $totalQueued  = 0;
        $totalBatches = 0;

        // A configurable child keeps its own independent status attribute --
        // disabling the parent does NOT disable its children. Without this,
        // an individually-still-Enabled child would sync on its own even
        // though its parent (the only thing actually shown/sold) is Disabled.
        $orphanedChildIds = $this->disabledParentResolver->getOrphanedChildIds($magentoStoreId);
        $orphanedChildIdSet = array_flip($orphanedChildIds);
        if (!empty($orphanedChildIds)) {
            $this->logger->info(sprintf(
                '[SmartSearch][FullSync] Excluding %d child product(s) of a disabled parent.',
                count($orphanedChildIds)
            ));
        }

        // A configurable whose own price attribute looks fine can still have
        // one variant carrying placeholder/bad pricing (e.g. 99999 on a single
        // color/size option) -- exclude the whole configurable listing in that
        // case, since checking the parent's own price alone would miss it.
        $overpricedParentIds = $this->disabledParentResolver->getConfigurableParentIdsWithOverpricedChild(
            self::MAX_SYNC_PRICE,
            $magentoStoreId
        );
        $overpricedParentIdSet = array_flip($overpricedParentIds);
        if (!empty($overpricedParentIds)) {
            $this->logger->info(sprintf(
                '[SmartSearch][FullSync] Excluding %d configurable product(s) with a variant priced above %.2f.',
                count($overpricedParentIds),
                self::MAX_SYNC_PRICE
            ));
        }

        $excludedIdSet = $orphanedChildIdSet + $overpricedParentIdSet;

        do {
            $rawItems = $this->fetchProductPage($page, $magentoStoreId);

            if (empty($rawItems)) {
                break;
            }

            $ids = empty($excludedIdSet)
                ? array_map(static fn($p) => (int) $p->getId(), $rawItems)
                : array_map(
                    static fn($p) => (int) $p->getId(),
                    array_filter($rawItems, static fn($p) =>
                        !isset($excludedIdSet[(int) $p->getId()])
                    )
                );

            if (!empty($ids)) {
                $this->publisher->publishBatch($ids, $magentoStoreId);

                $count = count($ids);
                $totalQueued  += $count;
                $totalBatches++;

                $this->logger->info(sprintf(
                    '[SmartSearch][FullSync] Queued page %d: %d product IDs (running total: %d).',
                    $page,
                    $count,
                    $totalQueued
                ));
            }

            $page++;
        } while (count($rawItems) === self::BATCH_SIZE);

        $this->logger->info(sprintf(
            '[SmartSearch][FullSync] Done queuing. products=%d batches=%d',
            $totalQueued,
            $totalBatches
        ));

        // ── Disabled/OOS sweep ───────────────────────────────────────────────
        // The upsert loop above only sends enabled+in-stock+price-eligible
        // products to the platform. Without this sweep, any product previously
        // synced but now disabled or OOS stays in the platform index — its
        // attributes (color, brand, size) inflate facet counts even though the
        // product never appears in results.
        // No updated_at cursor here: full sync always scans the entire catalog.
        // This mirrors the sweep in Cron\ProductSync::execute().
        $totalDeleted = 0;
        $totalDeleted += $this->sweepCollection(
            fn(int $page) => $this->buildDisabledOrOosCollection($magentoStoreId, $page),
            $magentoStoreId,
            'Disabled/OOS'
        );

        // ── Overpriced (non-configurable) sweep ─────────────────────────────
        // Same idea, for simple/virtual/bundle products previously synced but
        // now repriced above MAX_SYNC_PRICE. Configurables are excluded from
        // this collection entirely — their own price attribute isn't a
        // reliable signal, so they're handled by the resolver-based sweep below.
        $totalDeleted += $this->sweepCollection(
            fn(int $page) => $this->buildOverpricedCollection($magentoStoreId, $page),
            $magentoStoreId,
            'Overpriced'
        );

        // ── Overpriced-configurable-parent sweep ────────────────────────────
        // Separate from the paginated collections above since it's resolved
        // via catalog_product_super_link, not a simple attribute filter on the
        // main product collection. Reuses $overpricedParentIds computed at the
        // top of this method, so any of these already sitting in the platform
        // index from a prior sync (before a variant's price went bad) get
        // removed too.
        foreach (array_chunk($overpricedParentIds, self::BATCH_SIZE) as $chunk) {
            $delCount = $this->syncService->deleteBatch($chunk, $magentoStoreId);

            if ($delCount === -1) {
                $this->logger->error(sprintf(
                    '[SmartSearch][FullSync] Overpriced-configurable sweep: connection failed or invalid API key (store %d).',
                    $magentoStoreId
                ));
                break;
            }

            $totalDeleted += max(0, $delCount);
            $this->logger->info(sprintf(
                '[SmartSearch][FullSync] Overpriced-configurable sweep: deleted %d stale product(s) (store %d).',
                max(0, $delCount),
                $magentoStoreId
            ));
        }

        if ($totalDeleted > 0) {
            $this->logger->info(sprintf(
                '[SmartSearch][FullSync] All sweeps complete — removed %d stale product(s) from platform index.',
                $totalDeleted
            ));
        }

        return ['products' => $totalQueued, 'batches' => $totalBatches];
    }

    /**
     * Pages through $collectionFactory (one \Magento\Catalog...Collection per
     * page) deleting each page's IDs from the platform index. Shared pagination
     * loop for the Disabled/OOS and Overpriced sweeps, which only differ in how
     * the collection is filtered.
     */
    private function sweepCollection(callable $collectionFactory, int $storeId, string $label): int
    {
        $deletePage   = 1;
        $totalDeleted = 0;

        do {
            $delCollection = $collectionFactory($deletePage);
            $delIds        = array_map('intval', $delCollection->getAllIds());

            if (empty($delIds)) {
                break;
            }

            $delCount = $this->syncService->deleteBatch($delIds, $storeId);

            if ($delCount === -1) {
                $this->logger->error(sprintf(
                    '[SmartSearch][FullSync] %s sweep: connection failed or invalid API key (store %d).',
                    $label,
                    $storeId
                ));
                break;
            }

            $totalDeleted += max(0, $delCount);
            $this->logger->info(sprintf(
                '[SmartSearch][FullSync] %s sweep: deleted %d stale product(s) (store %d, page %d).',
                $label,
                max(0, $delCount),
                $storeId,
                $deletePage
            ));

            $deletePage++;
        } while (count($delIds) === self::BATCH_SIZE);

        return $totalDeleted;
    }

    /**
     * Products that are disabled OR out-of-stock — used by the full-sync sweep
     * to clean stale entries from the platform index. No updated_at cursor:
     * full sync always scans the entire catalog unconditionally.
     * Mirrors Cron\ProductSync::buildDisabledOrOosCollection().
     */
    private function buildDisabledOrOosCollection(int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
        $collection->addAttributeToSelect(['status']);

        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            ['stock_id' => 1],
            'left'
        );

        // status = disabled OR is_in_stock = 0 (left-joined, so NULL = no stock row = OOS)
        $collection->addFieldToFilter(
            ['status', 'is_in_stock'],
            [
                ['eq' => Status::STATUS_DISABLED],
                ['eq' => 0],
            ]
        );

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }

    /**
     * Non-configurable products priced above MAX_SYNC_PRICE — used by the
     * full-sync sweep to clean out stale entries left over from before this
     * product was repriced (or before this price ceiling existed). Configurable
     * parents are excluded here on purpose: their own price attribute isn't a
     * reliable signal, so they're covered by the separate resolver-based
     * overpriced-configurable-parent sweep in queueAll() instead, which checks
     * each variant's own price via catalog_product_super_link.
     */
    private function buildOverpricedCollection(int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }
        $collection->addAttributeToSelect(['price']);
        $collection->addAttributeToFilter('type_id', ['neq' => Configurable::TYPE_CODE]);
        $collection->addAttributeToFilter('price', ['gt' => self::MAX_SYNC_PRICE]);

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }

    private function fetchProductPage(int $page, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['sku', 'price']);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        // Price ceiling applies directly to simple/virtual/bundle products.
        // Configurables are let through here regardless of their own price
        // attribute (often null/unreliable) and are instead excluded above in
        // queueAll() via getConfigurableParentIdsWithOverpricedChild(), which
        // checks each variant's own price.
        $collection->addFieldToFilter(
            ['price', 'type_id'],
            [
                ['lteq' => self::MAX_SYNC_PRICE],
                ['eq' => Configurable::TYPE_CODE],
            ]
        );

        // OOS exclusion — full sync only, never the real-time save observer.
        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            ['stock_id' => 1],
            'inner'
        );
        $collection->addFieldToFilter('is_in_stock', 1);

        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }

        return array_values($collection->getItems());
    }
}
