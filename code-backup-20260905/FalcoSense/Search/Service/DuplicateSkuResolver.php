<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Batch/cron/full sync only — NOT used by the real-time save observer.
 * Resolves "duplicate" SKUs created by re-imports, e.g. hair-oil, hair-oil-1,
 * hair-oil-2, hair-oil-3, hair-oil-4 -> only hair-oil-4 is current.
 * A SKU that itself has a numbered child (hair-oil-1 has child hair-oil-1-1)
 * is always superseded; among leaf siblings sharing the same base, only the
 * highest-numbered one survives.
 *
 * Two guards keep this from misfiring on real, non-duplicate SKUs:
 *  - Only independently-visible products are considered candidates at all.
 *    A configurable/bundle child variant is hidden from individual view
 *    (visibility = Not Visible Individually) -- it's a real, currently
 *    sellable product, not a stray re-import copy, so it must never cause
 *    its own (also real, sellable) parent to be marked superseded just for
 *    sharing a numbered-looking SKU with it.
 *  - The numeric suffix must be short (<= 3 digits). A genuine re-import
 *    retry counter is realistically 1-2 digits; a long numeric suffix
 *    (8-14 digits) is almost always a UPC/GTIN barcode baked into otherwise
 *    unrelated SKUs that happen to share a brand prefix (e.g.
 *    SFTO-743404201313) -- treating those as siblings and keeping only the
 *    one with the numerically largest barcode silently discarded every
 *    other real, distinct product sharing that prefix.
 */
class DuplicateSkuResolver
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
    ) {}

    /**
     * Loads enabled, independently-visible SKUs for the given store and
     * returns the subset that are superseded by a higher-numbered duplicate.
     *
     * Runs the underlying collection query once and reuses it for both the
     * SKU and product-ID results — a caller that needs both (see
     * FullSyncCommand) should use getSupersededSkusAndIds() instead of
     * calling this and getSupersededProductIds() separately, which would
     * each re-run the same full-catalog collection query.
     */
    public function getSupersededSkus(int $storeId = 0): array
    {
        return $this->getSupersededSkusAndIds($storeId)['skus'];
    }

    /**
     * Same result as getSupersededSkus(), but returns product IDs instead of
     * SKUs — for callers that need to act on the products themselves (e.g.
     * deleting them from the search platform if an earlier sync already put
     * them there, before they became superseded).
     */
    public function getSupersededProductIds(int $storeId = 0): array
    {
        return $this->getSupersededSkusAndIds($storeId)['ids'];
    }

    /**
     * Combined form of getSupersededSkus() + getSupersededProductIds() that
     * loads the product collection exactly once. Prefer this over calling
     * both separately when a caller needs both results.
     *
     * @return array{skus: string[], ids: int[]}
     */
    public function getSupersededSkusAndIds(int $storeId = 0): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['sku', 'visibility']);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }

        $skus    = [];
        $idBySku = [];
        foreach ($collection as $product) {
            // Configurable/bundle children are intentionally hidden from
            // individual view -- they are real variants, never "duplicate
            // re-import" candidates, and must not enter this analysis at all.
            if ((int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
                continue;
            }
            $sku           = (string) $product->getSku();
            $skus[]        = $sku;
            $idBySku[$sku] = (int) $product->getId();
        }

        $supersededSkus = $this->resolveSuperseded($skus);
        $supersededIds  = array_values(array_map(static fn($sku) => $idBySku[$sku], $supersededSkus));

        return ['skus' => $supersededSkus, 'ids' => $supersededIds];
    }

    /**
     * Pure function over a flat SKU list — exposed separately so the dedup
     * logic can be unit tested without a Magento collection.
     */
    public function resolveSuperseded(array $skus): array
    {
        $skuSet = array_flip($skus);

        // parentBase => [suffixNumber => sku]
        // Suffix capped at 1-3 digits deliberately -- see class docblock.
        $parentMap = [];
        foreach ($skus as $sku) {
            if (preg_match('/^(.+)-(\d{1,3})$/', $sku, $m)) {
                $parentMap[$m[1]][(int) $m[2]] = $sku;
            }
        }

        // A SKU that is itself a parent base (has a numbered child) is superseded.
        $excluded = [];
        foreach (array_keys($parentMap) as $base) {
            if (isset($skuSet[$base])) {
                $excluded[$base] = true;
            }
        }

        // Among leaf siblings (children with no children of their own) sharing
        // the same base, only the highest-numbered one survives.
        foreach ($parentMap as $children) {
            $leaves = array_filter($children, static fn($sku) => !isset($excluded[$sku]));
            if (count($leaves) <= 1) {
                continue;
            }
            $maxSuffix = max(array_keys($leaves));
            foreach ($leaves as $suffix => $sku) {
                if ($suffix !== $maxSuffix) {
                    $excluded[$sku] = true;
                }
            }
        }

        return array_keys($excluded);
    }
}
