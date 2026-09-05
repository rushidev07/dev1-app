<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Product\Attribute\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

/**
 * Source for the product-level member discount TYPE attribute (percent | fixed).
 * "-- None --" (empty) means the product has no product-level member discount.
 */
class MemberDiscountType extends AbstractSource
{
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [
                ['label' => __('-- None --'), 'value' => ''],
                ['label' => __('Percentage off (%)'), 'value' => 'percent'],
                ['label' => __('Fixed amount off'), 'value' => 'fixed'],
            ];
        }
        return $this->_options;
    }
}
