<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Adminhtml\System\Config\Renderer;

use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\View\Element\Html\Select;

/**
 * Generic <select> column renderer for AbstractFieldArray dynamic-rows grids
 * (used by SellerInfoFieldArray for the "Seller Name" and "Rating" columns).
 * AbstractFieldArray calls setInputName()/setInputId() per row before
 * rendering, which is why those are overridden to feed name/id into the
 * underlying Select block instead of the default text-input attributes.
 */
class ColumnSelect extends Select
{
    private ?array $optionArray = null;

    /**
     * Options are supplied by the field array block via setData('option_source', ...)
     * on block creation, and cached here so a multi-row grid only builds the
     * list once instead of once per row.
     */
    public function _toHtml()
    {
        if ($this->optionArray === null) {
            $source = $this->getData('option_source');
            $this->optionArray = $source instanceof ArrayInterface ? $source->toOptionArray() : [];
        }
        $this->setOptions($this->optionArray);

        return parent::_toHtml();
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function setInputId($value)
    {
        return $this->setId($value);
    }
}


