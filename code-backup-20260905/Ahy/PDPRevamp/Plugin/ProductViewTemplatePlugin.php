<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Magento\Catalog\Block\Product\View;

/**
 * Swaps the PDP templates for the handful of Magento\Catalog\Block\Product\View
 * instances the revamp restyles (product.detail.page, product.info,
 * product.info.quantity, product.info.addtocart, product.info.addtowishlist,
 * product.info.form).
 *
 * Same reason GalleryTemplatePlugin/BreadcrumbsTemplatePlugin exist: theme
 * layout XML is merged after module layout XML, so a plain referenceBlock
 * template change from this module would be silently overwritten. Swapping
 * here at render time makes this module the sole source of truth regardless
 * of what the theme's catalog_product_view.xml declares - including
 * Ahy\BuyBox\Block\Product\View for product.detail.page, which extends this
 * same base class and so is covered by this plugin too.
 */
class ProductViewTemplatePlugin
{
    private const TEMPLATE_MAP = [
        'product.detail.page' => 'Ahy_PDPRevamp::product/product-detail-page.phtml',
        'product.info' => 'Ahy_PDPRevamp::product/view/product-info.phtml',
        'product.info.quantity' => 'Ahy_PDPRevamp::product/view/quantity.phtml',
        'product.info.addtocart' => 'Ahy_PDPRevamp::product/view/addtocart.phtml',
        'product.info.addtowishlist' => 'Ahy_PDPRevamp::product/view/addtowishlist.phtml',
        'product.info.form' => 'Ahy_PDPRevamp::product/view/product-form.phtml',
    ];

    public function beforeToHtml(View $subject): array
    {
        $template = self::TEMPLATE_MAP[$subject->getNameInLayout()] ?? null;
        if ($template !== null) {
            $subject->setTemplate($template);
        }
        return [];
    }
}
