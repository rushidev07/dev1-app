<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Ahy\PDPRevamp\Block\Product\View\StockStatus;

/**
 * Swaps the PDP stock-status block's template to the revamp version.
 *
 * Same reason GalleryTemplatePlugin/BreadcrumbsTemplatePlugin exist: theme
 * layout XML is merged after module layout XML, so the theme's plain
 * "product.info.stockstatus" declaration (Magento_Catalog::product/view/
 * stock-status.phtml, no template quantity text) silently overwrites this
 * module's referenceBlock template change. Swapping here at render time
 * makes this module the sole source of truth regardless of what the theme's
 * catalog_product_view.xml declares.
 */
class StockStatusTemplatePlugin
{
    private const REVAMP_TEMPLATE = 'Ahy_PDPRevamp::product/view/stock-status.phtml';

    public function beforeToHtml(StockStatus $subject): array
    {
        if ($subject->getNameInLayout() === 'product.info.stockstatus') {
            $subject->setTemplate(self::REVAMP_TEMPLATE);
        }
        return [];
    }
}
