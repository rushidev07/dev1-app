<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * 0.0-5.0 in 0.1 steps, for the "Rating" column of the PDP Seller Info
 * dynamic-rows grid (Stores > Configuration > AHY > PDP Seller Info). Replaces
 * free text entry with a guaranteed-valid list; the stored value is still the
 * plain numeric string, matching how Block\Product\View\SellerInfo already
 * casts this field to a float, so no downstream reading of this config value
 * needs to change.
 */
class Ratings implements ArrayInterface
{
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('-- None --')]];
        for ($i = 0; $i <= 50; $i++) {
            $value = number_format($i / 10, 1);
            $options[] = ['value' => $value, 'label' => $value];
        }

        return $options;
    }
}


