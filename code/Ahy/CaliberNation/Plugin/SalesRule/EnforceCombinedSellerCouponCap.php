<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin\SalesRule;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Config\Source\DiscountType;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\SalesRule\Model\Quote\Discount as DiscountCollector;

/**
 * Enforces an admin-configured cap on the combined seller discount + coupon discount.
 *
 * Context (Phase 3 addendum):
 *  - The 4-layer member pricing engine (membership + seller + category + product)
 *    already has its own global cap (Config::getCapValue) that limits the MEMBER
 *    discount stack. Coupons intentionally stack on top of that with no further limit
 *    (P0 §1.5).
 *  - This plugin adds an OPTIONAL secondary ceiling: when the seller layer contributed
 *    a member discount AND a coupon also applies to the same item, the combined total
 *    of those two discounts is capped at the configured value.
 *  - Non-seller items (no caliber_seller_discount data), non-member customers, and
 *    items without any coupon are all skipped — this cap is strictly additive to the
 *    existing behaviour and does not touch anything it shouldn't.
 *
 * Hook: afterCollect on Magento\SalesRule\Model\Quote\Discount.
 *  - Fires after all cart price rules have set discount_amount on items and
 *    accumulated the address discount total.
 *  - We trim the coupon portion of any over-cap item and adjust the address total
 *    in the same step so both item-level and total-level figures stay consistent.
 *
 * Safety guards (all must pass for an item to be touched):
 *  1. Master pricing switch enabled (isPricingEnabled).
 *  2. Combined cap feature enabled (isCombinedCapEnabled).
 *  3. Combined cap value > 0.
 *  4. Item is not a child row (parent_item_id absent).
 *  5. Item is not the membership product SKU.
 *  6. Item has caliber_seller_discount set (only written by ApplyMemberPrice for
 *     items where the seller layer actually contributed a non-zero discount).
 *  7. Item has a non-zero coupon discount_amount (nothing to cap otherwise).
 */
class EnforceCombinedSellerCouponCap
{
    public function __construct(private readonly Config $config) {}

    public function afterCollect(
        DiscountCollector $subject,
        DiscountCollector $result,
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): DiscountCollector {
        // Guard 1 & 2: master switch and combined cap feature must both be on.
        if (!$this->config->isPricingEnabled() || !$this->config->isCombinedCapEnabled()) {
            return $result;
        }

        // Guard 3: a cap value of 0 means "no cap configured" — nothing to do.
        $capValue = $this->config->getCombinedCapValue();
        if ($capValue <= 0.0) {
            return $result;
        }

        $membershipSku   = $this->config->getMembershipSku();
        $totalReduction  = 0.0;      // quote-currency total we trim across all items
        $baseReduction   = 0.0;      // base-currency equivalent for addBaseTotalAmount

        // base-to-quote conversion rate (1.0 for single-currency stores).
        $baseToQuoteRate = max(1.0, (float) $quote->getBaseToQuoteRate());

        $items = $shippingAssignment->getShipping()->getAddress()->getAllItems();
        foreach ($items as $item) {
            // Guard 4: skip child rows (e.g. configurable children); the parent carries
            // the line totals and is the row ApplyMemberPrice set custom_price on.
            if ($item->getParentItemId()) {
                continue;
            }

            // Guard 5: skip the membership product — it is never member-priced and is
            // handled exclusively by the win-back observer.
            if ($item->getSku() === $membershipSku) {
                continue;
            }

            // Guard 6: only act when ApplyMemberPrice stored a seller-layer discount
            // on this item. This also implicitly guards non-member carts (the observer
            // only runs for active members and only writes this data when seller > 0).
            $sellerDiscountPerUnit = (float) $item->getData('caliber_seller_discount');
            if ($sellerDiscountPerUnit <= 0.0) {
                continue;
            }

            // Guard 7: if no coupon discount was applied there is nothing to cap.
            $couponDiscount = (float) $item->getDiscountAmount();
            if ($couponDiscount <= 0.0) {
                continue;
            }

            $qty = max(1.0, (float) $item->getQty());

            // Seller discount stored per unit → convert to line total for comparison.
            $sellerDiscountLine = round($sellerDiscountPerUnit * $qty, 2);

            // Regular (original catalog) price for the % cap calculation.
            $product      = $item->getProduct();
            $regularPrice = $product ? (float) $product->getPrice() : 0.0;
            $regularLine  = $regularPrice * $qty;

            $capAmount = $this->computeCapAmount($regularLine, $capValue);
            $combined  = $sellerDiscountLine + $couponDiscount;

            if ($combined <= $capAmount) {
                // Combined discount is within the cap — leave this item alone.
                continue;
            }

            // Trim the coupon so that: seller_discount + allowed_coupon = cap.
            // We never allow the coupon to go below 0 (never increase the price).
            $allowedCoupon = round(max(0.0, $capAmount - $sellerDiscountLine), 2);
            $reduction     = round($couponDiscount - $allowedCoupon, 2);

            $item->setDiscountAmount($allowedCoupon);

            // base_discount_amount is in the store's base currency.
            $baseAllowedCoupon = round($allowedCoupon / $baseToQuoteRate, 2);
            $item->setBaseDiscountAmount($baseAllowedCoupon);

            $totalReduction += $reduction;
            $baseReduction  += round($reduction / $baseToQuoteRate, 2);
        }

        // Adjust the address discount totals to match the trimmed item amounts.
        // Magento stores discount as a negative amount; adding a positive value here
        // reduces the magnitude of the discount, keeping totals consistent with items.
        if ($totalReduction > 0.0) {
            $total->addTotalAmount('discount', $totalReduction);
            $total->addBaseTotalAmount('discount', $baseReduction);
        }

        return $result;
    }

    /**
     * Compute the cap ceiling for one line.
     *  - percent: cap = regularLine × capValue / 100
     *  - fixed:   cap = capValue flat (same regardless of line size)
     */
    private function computeCapAmount(float $regularLine, float $capValue): float
    {
        if ($this->config->getCombinedCapType() === DiscountType::FIXED) {
            return max(0.0, $capValue);
        }
        return max(0.0, round($regularLine * $capValue / 100, 2));
    }
}
