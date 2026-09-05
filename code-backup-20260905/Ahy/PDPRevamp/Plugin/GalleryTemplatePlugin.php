<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Magento\Catalog\Block\Product\View\Gallery;

/**
 * Swaps the PDP gallery template for the revamp version.
 * A plugin is required because theme layout XML would override
 * a plain referenceBlock template change from this module.
 */
class GalleryTemplatePlugin
{
    private const REVAMP_TEMPLATE = 'Ahy_PDPRevamp::product/view/gallery.phtml';

    public function beforeToHtml(Gallery $subject): array
    {
        if ($subject->getNameInLayout() === 'product.media') {
            $subject->setTemplate(self::REVAMP_TEMPLATE);
        }
        return [];
    }
}
