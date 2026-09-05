<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product\Attribute\Source;

use Ahy\PDPRevamp\Setup\Patch\Data\CreatePdpBadgeAttributes;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class BadgeIcon extends AbstractSource
{
    public function getAllOptions(): array
    {
        if ($this->_options === null) {
            $this->_options = [['value' => '', 'label' => __('-- Please Select --')]];
            foreach (CreatePdpBadgeAttributes::ICON_OPTIONS as $value => $label) {
                $this->_options[] = ['value' => $value, 'label' => __($label)];
            }
        }
        return $this->_options;
    }
}
