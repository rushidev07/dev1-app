<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Theme\Block\Html\Breadcrumbs;

/**
 * Swaps the PDP breadcrumb template for the revamp version.
 *
 * A plugin is required for the same reason GalleryTemplatePlugin needs one:
 * theme layout XML is merged after module layout XML, so a referenceBlock
 * template change from here loses. Concretely, the merged layout ends with
 * Hyva's own "product_breadcrumbs" block re-declaring
 * client_side_rendered_crumbs_template as Magento_Catalog::product/view/
 * breadcrumbs.phtml, and that block sets the template on this one during
 * _prepareLayout(). beforeToHtml runs later than both, so it wins outright.
 */
class BreadcrumbsTemplatePlugin
{
    private const REVAMP_TEMPLATE = 'Ahy_PDPRevamp::product/view/breadcrumbs.phtml';
    private const PDP_ACTION = 'catalog_product_view';

    private HttpRequest $request;

    public function __construct(HttpRequest $request)
    {
        $this->request = $request;
    }

    public function beforeToHtml(Breadcrumbs $subject): array
    {
        // Scoped to the PDP so category/CMS/search pages keep the sitewide
        // breadcrumb template until that design lands too.
        if ($this->request->getFullActionName() === self::PDP_ACTION) {
            $subject->setTemplate(self::REVAMP_TEMPLATE);
        }

        return [];
    }
}
