<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Discount-type options for member-pricing layers and the global cap.
 * Both types are expressed as an amount OFF the original price (additive model):
 *  - percent → a percentage off the original regular price
 *  - fixed   → a flat amount off
 */
class DiscountType implements OptionSourceInterface
{
    public const PERCENT = 'percent';
    public const FIXED   = 'fixed';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::PERCENT, 'label' => __('Percentage off (%)')],
            ['value' => self::FIXED,   'label' => __('Fixed amount off')],
        ];
    }
}
