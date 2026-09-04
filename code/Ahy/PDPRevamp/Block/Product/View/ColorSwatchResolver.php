<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\CssColorMap;
use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Ahy\PDPRevamp\Model\ResourceModel\VariantColor;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Swatches\Helper\Media as SwatchMediaHelper;

/**
 * PDP color-swatch-resolver block (see
 * product/color-swatch-resolver.phtml). Only exists to give that template
 * constructor-injected resource models instead of reaching for
 * ObjectManager::getInstance().
 */
class ColorSwatchResolver extends Template
{
    private VariantColor $variantColor;
    private CssColorMap $cssColorMap;
    private NativeColorSwatch $nativeColorSwatch;
    private SwatchMediaHelper $swatchMediaHelper;

    public function __construct(
        Context $context,
        VariantColor $variantColor,
        CssColorMap $cssColorMap,
        NativeColorSwatch $nativeColorSwatch,
        SwatchMediaHelper $swatchMediaHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->variantColor = $variantColor;
        $this->cssColorMap = $cssColorMap;
        $this->nativeColorSwatch = $nativeColorSwatch;
        $this->swatchMediaHelper = $swatchMediaHelper;
    }

    /**
     * Base URL an uploaded swatch image's stored relative path (e.g.
     * "/w/i/image.jpg") gets prefixed with to build a full, renderable URL -
     * same convention Magento_Swatches' own admin swatch-visual template
     * uses (Visual::reformatSwatchLabels()).
     */
    public function getSwatchMediaBaseUrl(): string
    {
        return $this->swatchMediaHelper->getSwatchMediaUrl();
    }

    public function getVariantColor(): VariantColor
    {
        return $this->variantColor;
    }

    public function getNativeColorSwatch(): NativeColorSwatch
    {
        return $this->nativeColorSwatch;
    }

    public function getColorHexMapJson(): string
    {
        return (string) json_encode($this->cssColorMap->toArray());
    }
}
