<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\Product\KeyFeaturesResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Block\Product\View;
use Magento\Catalog\Helper\Product as ProductHelper;
use Magento\Catalog\Model\ProductTypes\ConfigInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Json\EncoderInterface as JsonEncoderInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\Url\EncoderInterface as UrlEncoderInterface;

/**
 * PDP Key Features block (see product/view/key-features.phtml). Only
 * exists to give that template a constructor-injected KeyFeaturesResolver,
 * same pattern as ReviewQuote.php.
 */
class KeyFeatures extends View
{
    private KeyFeaturesResolver $keyFeaturesResolver;

    public function __construct(
        Context $context,
        UrlEncoderInterface $urlEncoder,
        JsonEncoderInterface $jsonEncoder,
        StringUtils $string,
        ProductHelper $productHelper,
        ConfigInterface $productTypeConfig,
        FormatInterface $localeFormat,
        CustomerSession $customerSession,
        ProductRepositoryInterface $productRepository,
        PriceCurrencyInterface $priceCurrency,
        KeyFeaturesResolver $keyFeaturesResolver,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $urlEncoder,
            $jsonEncoder,
            $string,
            $productHelper,
            $productTypeConfig,
            $localeFormat,
            $customerSession,
            $productRepository,
            $priceCurrency,
            $data
        );
        $this->keyFeaturesResolver = $keyFeaturesResolver;
    }

    public function getKeyFeaturesResolver(): KeyFeaturesResolver
    {
        return $this->keyFeaturesResolver;
    }
}
