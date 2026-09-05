<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Gallery;

use Magento\Catalog\Block\Adminhtml\Product\Helper\Form\Gallery\Content;

/**
 * Points the admin media-gallery panel at this module's template, which adds the
 * "Thumbnail Caption" field beside Alt Text.
 *
 * Two things make this a getTemplate() plugin rather than anything simpler:
 *
 * 1. The block is declared from layout XML (the <htmlContent name="gallery">
 *    node in ui_component/product_form.xml), so it takes its template from that
 *    declaration and its own $_template - a di.xml "template" data argument
 *    never reaches it.
 * 2. More importantly, Magento_ProductVideo's ChangeTemplateObserver (on the
 *    catalog_product_gallery_prepare_layout event) calls setTemplate() on this
 *    very block to swap in Magento_ProductVideo::helper/gallery.phtml - the
 *    video-aware 298-line version, which is what actually renders on the
 *    product page ("Images And Videos"). Anything that merely sets the template
 *    earlier is silently overwritten by that observer.
 *
 * Intercepting the getter runs after the observer has had its say, so this wins
 * regardless of ordering. Both known upstream templates are matched so the swap
 * still happens if Magento_ProductVideo is ever disabled.
 *
 * Our template is a copy of ProductVideo's (not Catalog's): it is the one in
 * effect, and it carries the video fields that would otherwise disappear from
 * the panel.
 */
class AdminGalleryTemplatePlugin
{
    private const UPSTREAM_TEMPLATES = [
        'Magento_ProductVideo::helper/gallery.phtml',
        'Magento_Catalog::catalog/product/helper/gallery.phtml',
    ];

    private const REVAMP_TEMPLATE = 'Ahy_PDPRevamp::helper/gallery.phtml';

    public function afterGetTemplate(Content $subject, $result)
    {
        if ($result === null || in_array($result, self::UPSTREAM_TEMPLATES, true)) {
            return self::REVAMP_TEMPLATE;
        }

        return $result;
    }
}
