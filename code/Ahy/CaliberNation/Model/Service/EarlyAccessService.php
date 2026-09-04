<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\Pricing\SellerResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Central service for early-access / early-bird feature logic.
 *
 * A product is "flagged" when:
 *   - caliber_early_access = 1 on the product itself, OR
 *   - its seller has is_early_access = 1 in ahy_caliber_nation_seller_participation
 *
 * All lookups cached per request.
 */
class EarlyAccessService
{
    private const SELLER_TABLE = 'ahy_caliber_nation_seller_participation';

    /** @var array<int,array<string,mixed>|null> */
    private array $sellerCache = [];

    public function __construct(
        private readonly Config $config,
        private readonly SellerResolver $sellerResolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ResourceConnection $resource
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Whether the early-access feature is enabled AND this product is flagged
     * (directly via product attribute or via its seller).
     */
    public function isEarlyAccessProduct(int $productId): bool
    {
        if (!$this->config->isEnabled() || !$this->config->isEarlyAccessEnabled()) {
            return false;
        }
        return $this->isProductAttributeFlagged($productId)
            || $this->isSellerFlagged($productId);
    }

    /**
     * Returns 'active' when the product is flagged for early access, 'disabled' otherwise.
     */
    public function getWindowStatus(int $productId): string
    {
        return $this->isEarlyAccessProduct($productId) ? 'active' : 'disabled';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function isProductAttributeFlagged(int $productId): bool
    {
        $product = $this->loadProduct($productId);
        return $product !== null && (int) $product->getData('caliber_early_access') === 1;
    }

    private function isSellerFlagged(int $productId): bool
    {
        $sellerId = $this->sellerResolver->getSellerId($productId);
        if (!$sellerId) {
            return false;
        }
        $row = $this->getSellerRow($sellerId);
        return $row !== null && (int) $row['is_early_access'] === 1;
    }

    private function loadProduct(int $productId): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        try {
            return $this->productRepository->getById($productId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function getSellerRow(int $sellerId): ?array
    {
        if (array_key_exists($sellerId, $this->sellerCache)) {
            return $this->sellerCache[$sellerId];
        }

        try {
            $conn   = $this->resource->getConnection();
            $table  = $this->resource->getTableName(self::SELLER_TABLE);
            $select = $conn->select()
                ->from($table)
                ->where('seller_id = ?', $sellerId)
                ->limit(1);
            $row = $conn->fetchRow($select);
        } catch (\Exception) {
            $row = false;
        }

        return $this->sellerCache[$sellerId] = ($row ?: null);
    }
}
