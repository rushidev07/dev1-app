<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Computes the Caliber Nation member price for a product from its
 * caliber_member_discount_enabled / caliber_member_discount_type /
 * caliber_member_discount_value attributes against the product's own
 * final_price - calibernation_price is never actually populated on any
 * product (confirmed empty catalog-wide), so it cannot be read directly;
 * this reproduces the discount live instead. Confirmed real stored values
 * (entity_id 318): enabled=1, type='fixed', value=10.000000.
 */
class CaliberMemberPrice implements ArgumentInterface
{
    private const ATTR_ENABLED = 'caliber_member_discount_enabled';
    private const ATTR_TYPE = 'caliber_member_discount_type';
    private const ATTR_VALUE = 'caliber_member_discount_value';

    /**
     * Returns the discounted member price, or null when the discount isn't
     * enabled, has no usable value/type, or wouldn't actually lower the price.
     */
    public function getCaliberPrice(Product $product): ?float
    {
        if (!(bool) $product->getData(self::ATTR_ENABLED)) {
            return null;
        }

        $value = (float) $product->getData(self::ATTR_VALUE);
        if ($value <= 0) {
            return null;
        }

        $basePrice = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        if ($basePrice <= 0) {
            return null;
        }

        $type = strtolower(trim((string) $product->getData(self::ATTR_TYPE)));

        if ($type === 'percent' || $type === 'percentage') {
            $discounted = $basePrice - ($basePrice * $value / 100);
        } elseif ($type === 'fixed' || $type === 'fixed_amount') {
            $discounted = $basePrice - $value;
        } else {
            return null;
        }

        $discounted = max(0.0, $discounted);
        if ($discounted >= $basePrice) {
            return null;
        }

        return round($discounted, 2);
    }
}
