<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class SliderInfo extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $cmsBlock = htmlspecialchars(
            '{{block class="FalcoSense\\Search\\Block\\Slider\\Products" slider_type="YOUR_SLUG_HERE" template="FalcoSense_Search::slider/smart-slider.phtml"}}',
            ENT_QUOTES,
            'UTF-8'
        );

        return <<<HTML
<div style="background:#f0f7ff;border:1px solid #b8d6f5;border-radius:6px;padding:18px 20px;max-width:680px;font-size:13px;line-height:1.6;color:#1e3a5f;">

    <p style="margin:0 0 14px;font-weight:700;font-size:14px;color:#0a2d5e;">
        How to add a Product Slider to your storefront
    </p>

    <ol style="margin:0 0 16px;padding-left:20px;">
        <li style="margin-bottom:8px;">
            Go to your <strong>Search Platform admin</strong> → <em>Sliders</em> page.
        </li>
        <li style="margin-bottom:8px;">
            Click <strong>"Create Slider"</strong>, fill in a title (e.g. <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">hunting-special</code>),
            choose a sort strategy (Popular / Newest / Custom), and optionally filter by brand or category.
        </li>
        <li style="margin-bottom:8px;">
            Once the slider is created, find its <strong>blue Slug badge</strong> on the slider card
            (e.g. <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">hunting-special</code>).
            Click it to copy the slug.
        </li>
        <li style="margin-bottom:8px;">
            In Magento go to <strong>Content → Blocks</strong>, open the CMS block where you want the slider,
            and paste the code below — replacing <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">YOUR_SLUG_HERE</code>
            with your copied slug.
        </li>
    </ol>

    <p style="margin:0 0 6px;font-weight:700;color:#0a2d5e;">CMS Block / Page-Builder HTML widget code:</p>
    <div style="position:relative;">
        <pre id="ss-slider-block-code" style="background:#1e293b;color:#e2e8f0;padding:12px 14px;border-radius:5px;font-size:12px;overflow-x:auto;margin:0;white-space:pre-wrap;word-break:break-all;">&lt;div data-content-type="html" data-appearance="default" data-element="main"&gt;{{block class="FalcoSense\\Search\\Block\\Slider\\Products" slider_type="YOUR_SLUG_HERE" template="FalcoSense_Search::slider/smart-slider.phtml"}}&lt;/div&gt;</pre>
        <button type="button"
                onclick="(function(btn){var pre=document.getElementById('ss-slider-block-code');navigator.clipboard.writeText(pre.innerText).then(function(){btn.textContent='Copied!';setTimeout(function(){btn.textContent='Copy';},2000);});})(this)"
                style="position:absolute;top:8px;right:8px;background:#3b82f6;color:#fff;border:none;border-radius:4px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">
            Copy
        </button>
    </div>

    <p style="margin:14px 0 0;color:#374151;">
        <strong>Tip:</strong> You can place multiple sliders on the same page — just use a different
        <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">slider_type</code> value for each block,
        matching a different slug from your Sliders admin.
    </p>

</div>
HTML;
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }
}
