<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Phrase\Renderer\Translate;

/**
 * PDP-only override for the theme's hardcoded preheader marquee copy
 * (Magento_Theme::html/header.phtml - core theme markup we don't edit
 * directly). Scoped to the "catalog_product_view" action only, unlike an
 * i18n dictionary entry which would apply to every page.
 *
 * This replaces an earlier client-side JS text swap: even run synchronously
 * right after the header markup, the browser could already have painted the
 * original text once, so the swap still counted as a Cumulative Layout
 * Shift. Rendering the correct copy in the very first HTML response avoids
 * that entirely.
 */
class PreheaderTranslatePlugin
{
    private const ORIGINAL_TEXT = "EVEREST.COM IS AN OUTDOOR GEAR MARKETPLACE SUPPORTING 1,000'S OF SELLERS - SHOP TODAY AND SAVE!";
    private const PDP_TEXT = 'Everest is a marketplace supporting thousands of sellers and millions of outdoor enthusiasts — shop today and save!';
    private const PDP_ACTION = 'catalog_product_view';

    private RequestInterface $request;

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    public function afterRender(Translate $subject, string $result, array $source, array $arguments): string
    {
        if ($result === self::ORIGINAL_TEXT && $this->request->getFullActionName() === self::PDP_ACTION) {
            return self::PDP_TEXT;
        }

        return $result;
    }
}
