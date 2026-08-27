<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Discount-type options for the seller-participation form. Same as DiscountType but
 * with a "None" choice, so a seller can opt in WITHOUT a blanket discount (and rely
 * on per-product Member Discounts instead) — mirroring the product-level field.
 * "None" (empty) means: participating, but no seller-wide discount.
 */
class SellerDiscountType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '',                    'label' => __('-- None (no blanket discount) --')],
            ['value' => DiscountType::PERCENT, 'label' => __('Percentage off (%)')],
            ['value' => DiscountType::FIXED,   'label' => __('Fixed amount off')],
        ];
    }
}
