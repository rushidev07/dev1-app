<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Magento\Framework\View\Element\AbstractBlock;
use Magento\Paypal\Block\Express\InContext\SmartButton;

class PaypalShortcutTemplatePlugin
{
    private const REVAMP_TEMPLATE = 'Ahy_PDPRevamp::product/view/paypal-shortcut.phtml';

    /**
     * Declared by the theme's Magento_Catalog/layout/catalog_product_view.xml,
     * not by this module - see EXTERNAL_DEPENDENCIES.md section 6.
     */
    private const PDP_SHORTCUT_CONTAINER = 'addtocart.shortcut.buttons';

    public function beforeToHtml(SmartButton $subject): array
    {
        $block = $subject;
        while ($block instanceof AbstractBlock) {
            if ($block->getNameInLayout() === self::PDP_SHORTCUT_CONTAINER) {
                $subject->setTemplate(self::REVAMP_TEMPLATE);
                break;
            }
            $block = $block->getParentBlock();
        }

        return [];
    }
}
