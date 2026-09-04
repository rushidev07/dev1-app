<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Adminhtml\System\Config;

use Ahy\PDPRevamp\Block\Adminhtml\System\Config\Renderer\ColumnSelect;
use Ahy\PDPRevamp\Model\Config\Source\Ratings;
use Ahy\PDPRevamp\Model\Config\Source\SellerNames;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/**
 * Renders the dynamic-rows editor for Stores > Configuration > General >
 * PDP Seller Info - one row per seller, each with the stats shown on the
 * PDP "Seller Info" tab. See Block\Product\View\SellerInfo for how a row is
 * matched to a real Webkul seller and applied as a fallback.
 */
class SellerInfoFieldArray extends AbstractFieldArray
{
    private SellerNames $sellerNames;
    private Ratings $ratings;
    private ?ColumnSelect $sellerNameRenderer = null;
    private ?ColumnSelect $ratingRenderer = null;

    public function __construct(
        Context $context,
        SellerNames $sellerNames,
        Ratings $ratings,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->sellerNames = $sellerNames;
        $this->ratings = $ratings;
    }

    protected function _prepareToRender()
    {
        $this->addColumn('seller_name', [
            'label' => __('Seller Name'),
            'class' => 'required-entry',
            'style' => 'width:350px',
            'renderer' => $this->getSellerNameRenderer(),
        ]);
        $this->addColumn('rating', [
            'label' => __('Rating (0-5)'),
            'style' => 'width:150px',
            'renderer' => $this->getRatingRenderer(),
        ]);
        $this->addColumn('review_count', ['label' => __('Review Count'), 'style' => 'width:150px']);
        $this->addColumn('response_time', ['label' => __('Avg. Response Time'), 'style' => 'width:170px']);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Seller');
    }

    private function getSellerNameRenderer(): ColumnSelect
    {
        if ($this->sellerNameRenderer === null) {
            $this->sellerNameRenderer = $this->getLayout()->createBlock(
                ColumnSelect::class,
                '',
                [
                    'data' => [
                        'option_source' => $this->sellerNames,
                        'class' => 'seller-info-searchable-select admin__control-select',
                        'extra_params' => 'style="width:300px;"',
                    ],
                ]
            );
        }

        return $this->sellerNameRenderer;
    }

    private function getRatingRenderer(): ColumnSelect
    {
        if ($this->ratingRenderer === null) {
            $this->ratingRenderer = $this->getLayout()->createBlock(
                ColumnSelect::class,
                '',
                [
                    'data' => [
                        'option_source' => $this->ratings,
                        'class' => 'pdp-rating-select admin__control-select',
                        'extra_params' => 'style="width:130px;"',
                    ],
                ]
            );
        }

        return $this->ratingRenderer;
    }

    /**
     * The dynamic-rows JS (Magento_Config's array.phtml) clones the row
     * template on every "Add Seller" click, so the seller_name <select>
     * needs to be select2-ified on an observer, not just once on page load.
     */
    public function _toHtml()
    {
        return parent::_toHtml() . $this->getResponsiveStyle() . $this->getPageFixScript();
    }

    /**
     * Lets the grid shrink on narrow admin viewports (sidebar expanded,
     * smaller browser window) instead of overflowing the panel: the grid
     * itself scrolls horizontally rather than breaking the page layout, and
     * below 900px the selects/inputs give up their fixed pixel widths so a
     * narrow viewport doesn't force horizontal scrolling before it has to.
     */
    private function getResponsiveStyle(): string
    {
        return <<<HTML
<style type="text/css">
/*
 * The field row is a flex layout (label + control side by side). Flex items
 * default to min-width:auto, so a wide table inside the control column
 * forces the whole row wider instead of respecting the table's own
 * overflow-x, which squeezes the label down to almost nothing (wrapping it
 * one letter per line). ":has()" targets whatever the real label/control
 * wrapper elements are without needing to know this theme's class names.
 */
*:has(> table:has(select.seller-info-searchable-select)) {
    min-width: 0 !important;
    flex-shrink: 1 !important;
}
*:has(> *:has(> table:has(select.seller-info-searchable-select))) > label,
*:has(> *:has(> table:has(select.seller-info-searchable-select))) > [class*="label" i] {
    flex-shrink: 0 !important;
    white-space: normal !important;
    word-break: normal !important;
}
table:has(select.seller-info-searchable-select),
table:has(select.pdp-rating-select) {
    max-width: 100%;
    display: block;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
@media (max-width: 900px) {
    select.seller-info-searchable-select,
    select.pdp-rating-select,
    table:has(select.seller-info-searchable-select) input.input-text {
        width: 100% !important;
        min-width: 90px !important;
    }
    .select2-container {
        max-width: 100% !important;
    }
}
</style>
HTML;
    }

    /**
     * select2-ifies the seller_name <select>, re-applied via a
     * MutationObserver since "Add Seller" clones a fresh row (and its own
     * plain <select>) on every click.
     */
    private function getPageFixScript(): string
    {
        $placeholder = $this->escapeJs(__('Search seller...')->render());

        return <<<HTML
<script type="text/javascript">
require(['jquery', 'select2'], function (\$) {
    var initSellerNameSelect2 = function () {
        \$('select.seller-info-searchable-select').not('.select2-hidden-accessible').select2({
            width: 'resolve',
            placeholder: '{$placeholder}',
            allowClear: true
        });
    };

    initSellerNameSelect2();

    if (window.MutationObserver) {
        new MutationObserver(initSellerNameSelect2).observe(document.body, {childList: true, subtree: true});
    }
});
</script>
HTML;
    }
}
