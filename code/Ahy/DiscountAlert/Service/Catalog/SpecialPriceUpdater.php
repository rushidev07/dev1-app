<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Catalog;

use Magento\Catalog\Model\Indexer\Product\Price\Processor as PriceIndexProcessor;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Indexer\CacheContext;
use Magento\Store\Model\Store;

/**
 * Adds and removes the special price on products chosen from the review grid.
 *
 * Removing the special price is what "revert to MSRP" means on this catalog. The FlxPoint
 * import writes the manufacturer's MSRP into `price` and the vendor's MAP into
 * `special_price`, so Magento renders MSRP struck through with MAP as the price paid.
 * Clearing `special_price` therefore leaves MSRP standing on its own - the value is
 * already correct, it is only being hidden behind the MAP.
 *
 * Products whose MSRP was missing at import time never received a special price at all
 * (the importer falls back to MAP as the plain price), so they are not flagged by the
 * alert and never reach this service. That is what makes "show MAP only when MSRP is
 * missing" hold without any extra rule here.
 *
 * Uses the same core action the native product grid mass action uses, so price indexing
 * and attribute-update events behave identically to an admin editing the product by hand.
 */
class SpecialPriceUpdater
{
    /**
     * Products per update statement. Keeps a large selection from building one huge query
     * and from queueing a single oversized reindex batch.
     */
    private const BATCH_SIZE = 500;

    /**
     * Scope the alert is evaluated and acted on at. Store-level price overrides are
     * deliberately out of scope, matching DiscountProductCollector.
     */
    private const SCOPE_STORE_ID = Store::DEFAULT_STORE_ID;

    public function __construct(
        private readonly ProductAction $productAction,
        private readonly PriceIndexProcessor $priceIndexProcessor,
        private readonly CacheContext $cacheContext,
        private readonly EventManager $eventManager
    ) {}

    /**
     * Drop the special price, leaving MSRP as the only price shown.
     *
     * @param int[] $productIds
     */
    public function remove(array $productIds): void
    {
        $productIds = $this->normalise($productIds);

        if (!$productIds) {
            return;
        }

        foreach (\array_chunk($productIds, self::BATCH_SIZE) as $chunk) {
            $this->productAction->updateAttributes(
                $chunk,
                ['special_price' => null],
                self::SCOPE_STORE_ID
            );
        }

        $this->refresh($productIds);
    }

    /**
     * Put previously removed special prices back.
     *
     * Grouped by value so products sharing a price are updated together, rather than one
     * statement per product: updateAttributes() applies a single set of values to a set of
     * IDs, so restoring distinct prices means one call per distinct price.
     *
     * @param array<int, float> $pricesByProductId
     */
    public function restore(array $pricesByProductId): void
    {
        $grouped = [];

        foreach ($pricesByProductId as $productId => $price) {
            $productId = (int) $productId;
            $price     = (float) $price;

            // A zero or negative special price is not a discount and would break the
            // storefront price display, so it is never written back.
            if ($productId <= 0 || $price <= 0) {
                continue;
            }

            $grouped[(string) $price][] = $productId;
        }

        $touched = [];

        foreach ($grouped as $price => $ids) {
            $ids = \array_values(\array_unique($ids));

            foreach (\array_chunk($ids, self::BATCH_SIZE) as $chunk) {
                $this->productAction->updateAttributes(
                    $chunk,
                    ['special_price' => (float) $price],
                    self::SCOPE_STORE_ID
                );
            }

            $touched = \array_merge($touched, $ids);
        }

        $this->refresh($touched);
    }

    /**
     * Reprice and drop the cached pages for the products just changed.
     *
     * Done explicitly rather than trusting the attribute update to cascade: a price the
     * storefront does not pick up until the next full reindex would look to the admin like
     * the action had silently failed. reindexList() is a no-op while the price indexer is
     * set to Update by Schedule, which is the right behaviour - the scheduler owns it then.
     *
     * @param int[] $productIds
     */
    private function refresh(array $productIds): void
    {
        if (!$productIds) {
            return;
        }

        $this->priceIndexProcessor->reindexList($productIds);

        $this->cacheContext->registerEntities(Product::CACHE_TAG, $productIds);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $this->cacheContext]);
    }

    /**
     * @param  int[] $productIds
     * @return int[]
     */
    private function normalise(array $productIds): array
    {
        return \array_values(\array_unique(\array_filter(\array_map('intval', $productIds))));
    }
}
