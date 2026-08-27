<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WinbackDiscountType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'percent', 'label' => __('Percent off standard price')],
            ['value' => 'fixed',   'label' => __('Fixed discounted price')],
        ];
    }
}
