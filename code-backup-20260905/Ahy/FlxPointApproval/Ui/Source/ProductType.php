<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Ui\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ProductType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'simple',       'label' => __('Simple')],
            ['value' => 'configurable', 'label' => __('Configurable')],
        ];
    }
}
