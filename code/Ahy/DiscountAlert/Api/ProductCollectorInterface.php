<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Api;

/**
 * Finds catalog products whose special price sits more than a given percentage
 * below their regular price.
 *
 * @api
 */
interface ProductCollectorInterface
{
    public const KEY_PRODUCT_ID    = 'product_id';
    public const KEY_SKU           = 'sku';
    public const KEY_NAME          = 'name';
    public const KEY_PRICE         = 'price';
    public const KEY_SPECIAL_PRICE = 'special_price';
    public const KEY_DISCOUNT_PCT  = 'discount_pct';
    public const KEY_STATUS        = 'status';

    /**
     * Total number of matching products, ignoring any limit.
     */
    public function count(float $threshold): int;

    /**
     * @param  float $threshold Discount percentage a product must exceed.
     * @param  int   $limit     Maximum rows to return; zero for no limit.
     * @return array<int, array<string, mixed>> Rows keyed by the KEY_* constants,
     *                                          ordered by discount percentage descending.
     */
    public function getList(float $threshold, int $limit = 0): array;
}
