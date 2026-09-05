<?php
declare(strict_types=1);

namespace FalcoSense\Search\Cron;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\DisabledParentResolver;
use FalcoSense\Search\Service\ProductSyncService;
use FalcoSense\Search\Service\SyncLockManager;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Delta sync — runs every minute via cron, sends only products changed
 * since the last successful run to POST /api/v1/ingest/products.
 * Loops every store that has its own (or an inherited default) API key configured,
 * so each store's products sync under that store's key/platform store id.
 * Full sync: bin/magento smartsearch:sync:full
 */
class ProductSync
{
    private const BATCH_SIZE = 300;

    public function __construct(
        private readonly Data                  $helper,
        private readonly ProductSyncService    $syncService,
        private readonly CollectionFactory     $collectionFactory,
        private readonly LoggerInterface       $logger,
        private readonly SyncLockManager       $lockManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly DisabledParentResolver $disabledParentResolver,
    ) {}

    public function execute(): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        if (!$this->lockManager->acquireOrTakeOver('cron')) {
            $info = $this->lockManager->getLockInfo();
            $this->logger->warning(sprintf(
                '[SmartSearch][Cron] Sync already running (via %s, started %s) — skipping.',
                $info['source']  ?? 'unknown',
                $info['started'] ?? 'unknown'
            ));
            return;
        }

        $lockManager = $this->lockManager;
        register_shutdown_function(static function () use ($lockManager): void {
            $lockManager->release();
        });

        $syncStartedAt = date('Y-m-d H:i:s');
        $forceFullSync = $this->helper->isFullSyncRequested();
        $lastSyncAt    = $forceFullSync ? null : $this->helper->getLastSyncAt();

        $storeIds = $this->getConfiguredStoreIds();

        if (empty($storeIds)) {
            $this->logger->warning('[SmartSearch][Cron] No stores with an API key configured — skipping.');
            $this->lockManager->writeResult(false, 'No stores with an API key configured.');
            $this->lockManager->release();
            return;
        }

        $this->logger->info(sprintf(
            '[SmartSearch][Cron] Sync started. mode=%s cursor=%s stores=%s',
            $forceFullSync ? 'full' : 'delta',
            $lastSyncAt ?? 'none',
            implode(',', $storeIds)
        ));

        $totalSynced = 0;
        $aborted     = false;
        $hadFatal    = false;

        try {
            foreach ($storeIds as $storeId) {
                if ($aborted) {
                    break;
                }

                $page = 1;

                // A configurable child keeps its own independent status attribute --
                // disabling the parent does NOT disable its children. Without this,
                // an individually-still-Enabled child would sync on its own even
                // though its parent (the only thing actually shown/sold) is Disabled.
                $orphanedChildIds = $this->disabledParentResolver->getOrphanedChildIds($storeId);
                if (!empty($orphanedChildIds)) {
                    $this->logger->info(sprintf(
                        '[SmartSearch][Cron] Excluding %d child product(s) of a disabled parent for store %d.',
                        count($orphanedChildIds),
                        $storeId
                    ));
                }

                // Overpriced configurable family — parent and/or a child priced above
                // the sync cap. The whole family is excluded together (see
                // DisabledParentResolver::getOverpricedConfigurableFamilyIds()).
                $overpricedFamilyIds = $this->disabledParentResolver->getOverpricedConfigurableFamilyIds(Data::MAX_SYNC_PRICE, $storeId);
                if (!empty($overpricedFamilyIds)) {
                    $this->logger->info(sprintf(
                        '[SmartSearch][Cron] Excluding %d product(s) from an overpriced configurable family (>%s) for store %d.',
                        count($overpricedFamilyIds),
                        Data::MAX_SYNC_PRICE,
                        $storeId
                    ));
                }

                $excludedIdSet = array_flip(array_unique(array_merge($orphanedChildIds, $overpricedFamilyIds)));

                do {
                    if (!$this->lockManager->isLocked()) {
                        $this->logger->warning('[SmartSearch][Cron] Lock file removed — sync aborted by external signal.');
                        $this->lockManager->writeResult(false, 'Aborted by external signal.');
                        $aborted = true;
                        break;
                    }

                    $collection  = $this->buildCollection($lastSyncAt, $storeId, $page);
                    $rawProducts = array_values($collection->getItems());

                    if (empty($rawProducts)) {
                        break;
                    }

                    $products = empty($excludedIdSet)
                        ? $rawProducts
                        : array_values(array_filter($rawProducts, static fn($p) =>
                            !isset($excludedIdSet[(int) $p->getId()])
                        ));

                    if (!empty($products)) {
                        $count = $this->syncService->syncBatch($products, $storeId);

                        if ($count === -1) {
                            $msg = sprintf('Connection failed or invalid API key for store %d — cursor not advanced.', $storeId);
                            $this->logger->error('[SmartSearch][Cron] ' . $msg);
                            $hadFatal = true;
                            break;
                        }

                        $totalSynced += max(0, $count);
                    }

                    $page++;
                } while (count($rawProducts) === self::BATCH_SIZE);

                if ($aborted || $hadFatal) {
                    continue;
                }

                // ── Disabled/OOS sweep ───────────────────────────────────────────
                // Closes the gap the real-time observers can't cover: Magento's admin
                // "Update Attributes" mass action (status) and any bulk stock update
                // write directly via Product\Action/StockRegistry APIs and never
                // dispatch catalog_product_save_after / cataloginventory_stock_item_save_after,
                // so ProductSaveObserver/StockChangeObserver never fire for them.
                // This sweep finds anything that became disabled/OOS since the last
                // cursor (or, on a full sync, everything currently disabled/OOS) and
                // UPSERTS it with its real (disabled/OOS) status/stock instead of
                // deleting it — the platform's own search-time gate already excludes
                // any document with is_active=false or in_stock=false (see
                // OpenSearchService::searchProductsWithFilters()'s $gateClauses), so
                // this is enough to keep it out of results without depending on the
                // per-product-loop delete endpoint, which the sweep used to call one
                // product at a time and which times out under load (each call is a
                // synchronous OpenSearch round-trip inside ProductIngestService::
                // deleteProducts()'s foreach — see the Aug 2026 sweep-503 investigation).
                $deletePage = 1;
                do {
                    if (!$this->lockManager->isLocked()) {
                        $this->logger->warning('[SmartSearch][Cron] Lock file removed — sync aborted by external signal.');
                        $this->lockManager->writeResult(false, 'Aborted by external signal.');
                        $aborted = true;
                        break;
                    }

                    $delCollection = $this->buildDisabledOrOosCollection($lastSyncAt, $storeId, $deletePage);
                    $oosProducts   = array_values($delCollection->getItems());

                    if (empty($oosProducts)) {
                        break;
                    }

                    $delCount = $this->syncService->syncBatch($oosProducts, $storeId);

                    if ($delCount === -1) {
                        $this->logger->error(sprintf(
                            '[SmartSearch][Cron] Disabled/OOS sweep: connection failed or invalid API key for store %d.',
                            $storeId
                        ));
                        $hadFatal = true;
                        break;
                    }

                    $this->logger->info(sprintf(
                        '[SmartSearch][Cron] Disabled/OOS sweep marked %d product(s) unavailable for store %d.',
                        max(0, $delCount), $storeId
                    ));

                    $deletePage++;
                } while (count($oosProducts) === self::BATCH_SIZE);

                // Deliberately no "overpriced-family sweep" here — an overpriced
                // family is excluded from being SYNCED (see $excludedIdSet above and
                // buildCollection()'s price filter), but anything already indexed on
                // the platform from before this cap existed is left untouched. Only
                // disabled/OOS products get actively re-synced as unavailable (swept
                // just above).
            }

            if (!$aborted) {
                if ($hadFatal) {
                    $this->lockManager->writeResult(false, "Synced {$totalSynced} products before a fatal error (cursor not advanced).");
                } else {
                    $this->helper->setLastSyncAt($syncStartedAt);

                    if ($forceFullSync) {
                        $this->helper->clearFullSyncFlag();
                    }

                    $this->lockManager->writeResult(true, "Synced {$totalSynced} products.");
                }
                $this->logger->info('[SmartSearch][Cron] Done. synced=' . $totalSynced . ' cursor=' . $syncStartedAt);
            }

        } finally {
            $this->lockManager->release();
        }
    }

    /**
     * All store IDs that resolve a non-empty API key — either their own store-scoped
     * override, or the inherited website/default value if no override is set.
     */
    private function getConfiguredStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $sid = (int) $store->getId();
            if ($this->helper->getApiKey($sid)) {
                $ids[] = $sid;
            }
        }
        return $ids;
    }

    private function buildCollection(?string $lastSyncAt, int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);

        $collection->addAttributeToSelect([
            'name', 'sku', 'price', 'special_price', 'status', 'visibility',
            'url_key', 'description', 'short_description', 'image',
            'color', 'material', 'size', 'manufacturer', 'product_brand',
        ]);

        // Disabled-product exclusion — this collection previously had no status
        // filter, so disabled products were syncing to the platform via cron.
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        // Overpriced exclusion — placeholder/test pricing (e.g. 99999) must not sync.
        // NULL is allowed through: configurable parents routinely carry no price of
        // their own, deriving one from their cheapest child instead (see
        // ProductSyncService::normalize()) — that per-child check happens separately
        // via $excludedIdSet above, since a bad CHILD price can hide behind a NULL or
        // perfectly fine parent-level price attribute.
        $collection->addAttributeToFilter('price', [
            ['null' => true],
            ['lteq' => Data::MAX_SYNC_PRICE],
        ]);

        if ($lastSyncAt !== null) {
            $collection->addAttributeToFilter('updated_at', ['gt' => $lastSyncAt]);
        }

        // OOS exclusion — full/cron sync only, never the real-time save observer.
        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            ['stock_id' => 1],
            'inner'
        );
        $collection->addFieldToFilter('is_in_stock', 1);

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }

    /**
     * Products that are disabled OR out-of-stock — the inverse of buildCollection()'s
     * filter — used to sweep-resync anything a bulk/API update pushed into that state
     * without going through the real-time observers, so the platform's copy of it
     * reflects the true disabled/OOS status. On a full sync ($lastSyncAt null) this
     * deliberately has no updated_at floor, so it also backfills anything that became
     * disabled/OOS before this sweep existed.
     *
     * Selects the same attribute set as buildCollection() (not just 'status') because
     * the products found here are re-synced via ProductSyncService::syncBatch(), which
     * needs the full attribute set to build a correct normalize() payload.
     */
    private function buildDisabledOrOosCollection(?string $lastSyncAt, int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect([
            'name', 'sku', 'price', 'special_price', 'status', 'visibility',
            'url_key', 'description', 'short_description', 'image',
            'color', 'material', 'size', 'manufacturer', 'product_brand',
        ]);

        $collection->joinField(
            'is_in_stock',
            'cataloginventory_stock_item',
            'is_in_stock',
            'product_id=entity_id',
            ['stock_id' => 1],
            'left'
        );

        // status = disabled OR is_in_stock = 0 (left-joined, so NULL counts as "no stock row" too).
        // Deliberately does NOT include price here — overpriced products are excluded
        // from being synced (see buildCollection()) but are never actively deleted if
        // already on the platform.
        //
        // NOTE: must be ONE array where each element embeds its own 'attribute' key —
        // this is Magento's real multi-condition OR shape (see AbstractCollection::
        // addAttributeToFilter(), which does `$condition['attribute']` inside the loop).
        // The previous two-separate-arrays form — addFieldToFilter(['status','is_in_stock'],
        // [['eq'=>...],['eq'=>...]]) — silently threw a TypeError under PHP 8 ("Cannot
        // access offset of type string on string") every time this ran, meaning the
        // disabled/OOS sweep has not actually been running at all.
        $collection->addFieldToFilter([
            ['attribute' => 'status', 'eq' => Status::STATUS_DISABLED],
            ['attribute' => 'is_in_stock', 'eq' => 0],
        ]);

        if ($lastSyncAt !== null) {
            $collection->addAttributeToFilter('updated_at', ['gt' => $lastSyncAt]);
        }

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }
}
