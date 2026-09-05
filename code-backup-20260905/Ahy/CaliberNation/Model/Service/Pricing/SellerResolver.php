<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service\Pricing;

use Magento\Framework\App\ResourceConnection;

/**
 * Resolves the Webkul marketplace seller that owns a product, via
 * marketplace_product.mageproduct_id → seller_id. Result cached per request.
 * Returns null for admin-owned / non-marketplace products.
 */
class SellerResolver
{
    private const TABLE = 'marketplace_product';

    /** @var array<int,int|null> */
    private array $cache = [];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function getSellerId(int $productId): ?int
    {
        if ($productId <= 0) {
            return null;
        }
        if (array_key_exists($productId, $this->cache)) {
            return $this->cache[$productId];
        }

        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);
            $select = $conn->select()
                ->from($table, ['seller_id'])
                ->where('mageproduct_id = ?', $productId)
                ->limit(1);
            $sellerId = $conn->fetchOne($select);
            $result = $sellerId !== false && $sellerId !== null ? (int) $sellerId : null;
        } catch (\Exception) {
            // Marketplace module/table absent → treat as no seller.
            $result = null;
        }

        return $this->cache[$productId] = $result;
    }
}
