<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Renders the "Sponsor Banner Placements" repeatable grid in
 * Stores > Configuration > AvantLink Affiliate Tracking, letting admins map
 * a CMS block (a sponsored banner) to the AvantLink merchant it should be
 * tracked against, without a code deploy per placement.
 */
class BannerPlacements extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('block_id', [
            'label' => __('CMS Block Identifier'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('merchant_id', [
            'label' => __('AvantLink Merchant ID'),
        ]);
        $this->addColumn('tracking_code', [
            'label' => __('Tracking Code (ctc)'),
        ]);

        $this->_addAfterElementJs = false;
        $this->addButtonLabel = __('Add Banner Placement');
    }
}
