<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;

/**
 * Trims the product form's "Member Discount Value" to 2 decimals.
 *
 * The attribute is an EAV decimal, so it loads as "50.000000" — four trailing
 * zeros of noise on a field an admin reads as money or a percent. Display only:
 * the stored value and its precision are untouched, and saving a 2-decimal
 * number round-trips identically.
 */
class MemberDiscountDecimal extends AbstractModifier
{
    private const FIELD     = 'caliber_member_discount_value';
    private const PRECISION = 2;

    public function modifyData(array $data): array
    {
        foreach ($data as $productId => $productData) {
            $value = $productData[self::DATA_SOURCE_DEFAULT][self::FIELD] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                $data[$productId][self::DATA_SOURCE_DEFAULT][self::FIELD] =
                    number_format((float) $value, self::PRECISION, '.', '');
            }
        }

        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        return $meta;
    }
}
