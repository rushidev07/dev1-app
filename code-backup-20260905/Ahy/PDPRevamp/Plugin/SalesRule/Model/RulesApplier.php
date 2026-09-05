<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\SalesRule\Model;

use Ahy\PDPRevamp\Block\Product\View\FrequentlyBoughtTogether;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\RulesApplier as RulesApplierSubject;
use Magento\SalesRule\Model\Validator;

/**
 * Applies the real per-item discount for a PDP "Frequently Bought Together"
 * bundle add (see view/frontend/templates/product/view/
 * frequently-bought-together.phtml and
 * Plugin\Checkout\Model\Cart\TagFbtBundleItem, which tags each item's
 * quote_item_option with ahy_fbt_bundle_id/ahy_fbt_bundle_size at add time).
 *
 * Same extension point (Magento\SalesRule\Model\RulesApplier::applyRules(),
 * called once per quote item during normal totals collection) that
 * Amasty\Mostviewed\Plugin\SalesRule\Model\RulesApplier already uses in this
 * codebase for its own, unrelated bundle-pack widget - this plugin has no
 * dependency on any Amasty class, it just follows the same proven mechanism:
 * write straight into the item's own discount_amount/base_discount_amount,
 * the same fields a real Cart Price Rule would set.
 *
 * Deliberately additive (never overwrites $item->getDiscountAmount()) and
 * leaves $appliedRuleIds untouched, so this always stacks on top of any
 * other active coupon/cart rule rather than suppressing it - see the FBT
 * discount plan's stacking decision.
 */
class RulesApplier
{
    private const OPTION_BUNDLE_ID = 'ahy_fbt_bundle_id';
    private const OPTION_BUNDLE_SIZE = 'ahy_fbt_bundle_size';

    private Validator $validator;

    public function __construct(Validator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * @param mixed $rules
     * @param mixed $skipValidation
     * @param mixed $couponCode
     */
    public function afterApplyRules(
        RulesApplierSubject $subject,
        array $appliedRuleIds,
        $item = null,
        $rules = null,
        $skipValidation = null,
        $couponCode = null
    ): array {
        if (!$item instanceof AbstractItem) {
            return $appliedRuleIds;
        }

        $bundleIdOption = $item->getOptionByCode(self::OPTION_BUNDLE_ID);
        $bundleSizeOption = $item->getOptionByCode(self::OPTION_BUNDLE_SIZE);
        if (!$bundleIdOption || !$bundleSizeOption) {
            return $appliedRuleIds;
        }

        $bundleId = $bundleIdOption->getValue();
        $bundleSize = (int) $bundleSizeOption->getValue();
        if ($bundleId === null || $bundleId === '' || $bundleSize < 2) {
            return $appliedRuleIds;
        }

        if (!$this->allBundleSiblingsStillInCart($item, $bundleId, $bundleSize)) {
            return $appliedRuleIds;
        }

        $qty = (float) $item->getTotalQty();
        $itemPrice = (float) $this->validator->getItemPrice($item);
        $baseItemPrice = (float) $this->validator->getItemBasePrice($item);

        $percent = FrequentlyBoughtTogether::BUNDLE_DISCOUNT_PERCENT;
        $discount = min(round($qty * $itemPrice * $percent / 100, 2), $qty * $itemPrice);
        $baseDiscount = min(round($qty * $baseItemPrice * $percent / 100, 2), $qty * $baseItemPrice);

        if ($discount > 0) {
            $item->setDiscountAmount((float) $item->getDiscountAmount() + $discount);
            $item->setBaseDiscountAmount((float) $item->getBaseDiscountAmount() + $baseDiscount);
        }

        return $appliedRuleIds;
    }

    /**
     * The discount only holds while every item originally added together for
     * this bundle is still in the cart - if the shopper later removes one,
     * the sibling count here drops below $bundleSize and the discount stops
     * for whatever's left, with no extra bookkeeping needed.
     */
    private function allBundleSiblingsStillInCart(AbstractItem $item, string $bundleId, int $bundleSize): bool
    {
        $quote = $item->getQuote();
        if (!$quote) {
            return false;
        }

        $siblingCount = 0;
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $option = $quoteItem->getOptionByCode(self::OPTION_BUNDLE_ID);
            if ($option && $option->getValue() === $bundleId) {
                $siblingCount++;
            }
        }

        return $siblingCount >= $bundleSize;
    }
}
