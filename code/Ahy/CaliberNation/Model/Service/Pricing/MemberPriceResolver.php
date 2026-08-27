<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service\Pricing;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Config\Source\DiscountType;
use Magento\Catalog\Api\Data\ProductInterface;

/**
 * THE member-pricing engine (Phase 3, single source of truth).
 *
 * Model (locked P0 decisions — see docs/Phase3-Plan.md §1):
 *   - ADDITIVE stack of layers: membership base + seller + category(+) + product.
 *   - Each layer is percent (off the ORIGINAL price) or fixed (flat amount off).
 *   - Order is irrelevant (commutative): total discount = Σ layers.
 *   - Global cap clamps the TOTAL member discount (percent of original, or fixed).
 *   - Result never below 0. Coupons/cart rules stack on top later (handled by Magento).
 *
 * This resolver does NOT decide whether the customer is a member — callers apply
 * the member price only for active members (display shows it to everyone as a teaser).
 */
class MemberPriceResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly RuleProvider $ruleProvider,
        private readonly SellerResolver $sellerResolver
    ) {}

    /**
     * Resolve for a catalog product. Uses the product's category ids + resolved
     * seller. Pass an explicit $regularPrice to price a specific selection/qty tier.
     */
    public function resolveForProduct(ProductInterface $product, ?float $regularPrice = null): PriceResult
    {
        $regular    = $regularPrice ?? (float) $product->getPrice();
        $productId  = (int) $product->getId();
        $categoryIds = method_exists($product, 'getCategoryIds')
            ? array_map('intval', (array) $product->getCategoryIds())
            : [];
        $sellerId = $this->sellerResolver->getSellerId($productId);

        return $this->resolve($regular, $productId, $categoryIds, $sellerId, $this->extractProductDiscount($product));
    }

    /**
     * Product-level discount comes from the product's own attributes
     * (caliber_member_discount_type / _value), set on the product edit form.
     *
     * @return array{type:string,value:float}|null
     */
    private function extractProductDiscount(ProductInterface $product): ?array
    {
        // Explicit ON/OFF: a configured type/value only applies when enabled, so it
        // can be paused without losing the numbers (caliber_member_discount_enabled).
        if (!(int) $product->getData('caliber_member_discount_enabled')) {
            return null;
        }

        $type  = (string) $product->getData('caliber_member_discount_type');
        $value = (float) $product->getData('caliber_member_discount_value');
        if ($value > 0 && ($type === DiscountType::PERCENT || $type === DiscountType::FIXED)) {
            return ['type' => $type, 'value' => $value];
        }
        return null;
    }

    /**
     * Low-level resolve. Deterministic: member_price = regular − min(Σ layers, cap).
     *
     * @param int[] $categoryIds
     */
    public function resolve(float $regularPrice, ?int $productId, array $categoryIds, ?int $sellerId, ?array $productDiscount = null): PriceResult
    {
        if (!$this->config->isPricingEnabled() || $regularPrice <= 0) {
            return new PriceResult($regularPrice, $regularPrice, 0.0, 0.0, false, []);
        }

        // Seller participation is the gate for ALL member pricing on a seller's
        // products. A product owned by a Webkul seller only gets member pricing if
        // that seller is participating (enabled — the blanket discount value may be
        // 0, meaning "opted in, discount only specific products"). Admin-owned
        // products (no seller) are never gated.
        $sellerRule = $this->ruleProvider->getSellerDiscount($sellerId);
        if ($sellerId !== null && $sellerRule === null) {
            return new PriceResult($regularPrice, $regularPrice, 0.0, 0.0, false, []);
        }

        $layers = [];

        // 1. Membership base (all products, every active member).
        $baseValue = $this->config->getBaseDiscountValue();
        if ($baseValue > 0) {
            $d = $this->computeOff($regularPrice, $this->config->getBaseDiscountType(), $baseValue);
            if ($d > 0) {
                $layers[] = ['layer' => 'membership', 'type' => $this->config->getBaseDiscountType(), 'value' => $baseValue, 'discount' => $d];
            }
        }

        // 2. Seller blanket discount ($sellerRule fetched above for the gate). Only a
        //    real percent/fixed type counts — "None" means opted in with no blanket
        //    (per-product Member Discounts still apply; participation gate already passed).
        if ($sellerRule && \in_array($sellerRule['type'], [DiscountType::PERCENT, DiscountType::FIXED], true)) {
            $d = $this->computeOff($regularPrice, $sellerRule['type'], $sellerRule['value']);
            if ($d > 0) {
                $layers[] = ['layer' => 'seller', 'type' => $sellerRule['type'], 'value' => $sellerRule['value'], 'discount' => $d, 'target_id' => (int) $sellerId];
            }
        }

        // 3. Category (sum all matching category rules — additive).
        foreach ($this->ruleProvider->getCategoryDiscounts($categoryIds) as $catRule) {
            $d = $this->computeOff($regularPrice, $catRule['type'], $catRule['value']);
            if ($d > 0) {
                $layers[] = ['layer' => 'category', 'type' => $catRule['type'], 'value' => $catRule['value'], 'discount' => $d, 'target_id' => $catRule['target_id']];
            }
        }

        // 4. Product — from the product's own attributes (set on the product form).
        if ($productDiscount) {
            $d = $this->computeOff($regularPrice, $productDiscount['type'], $productDiscount['value']);
            if ($d > 0) {
                $layers[] = ['layer' => 'product', 'type' => $productDiscount['type'], 'value' => $productDiscount['value'], 'discount' => $d, 'target_id' => (int) $productId];
            }
        }

        $rawDiscount = 0.0;
        foreach ($layers as $l) {
            $rawDiscount += $l['discount'];
        }
        $rawDiscount = round($rawDiscount, 2);

        // Global cap on the TOTAL member discount.
        $applied = $rawDiscount;
        $capValue = $this->config->getCapValue();
        if ($capValue > 0) {
            $capAmount = $this->computeOff($regularPrice, $this->config->getCapType(), $capValue);
            $applied = min($rawDiscount, $capAmount);
        }
        $capped = $applied < $rawDiscount;

        // Never discount below zero.
        $applied = min($applied, $regularPrice);
        $applied = round(max(0.0, $applied), 2);

        $memberPrice = round($regularPrice - $applied, 2);

        return new PriceResult($regularPrice, $memberPrice, $rawDiscount, $applied, $capped, $layers);
    }

    /** Amount OFF for one layer: percent → % of original; fixed → the flat amount. */
    private function computeOff(float $regularPrice, string $type, float $value): float
    {
        if ($type === DiscountType::FIXED) {
            return round(max(0.0, $value), 2);
        }
        // percent
        return round($regularPrice * max(0.0, $value) / 100, 2);
    }
}
