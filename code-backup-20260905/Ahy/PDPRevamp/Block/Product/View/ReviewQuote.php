<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Service\YotpoClient;
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
 * PDP review-quote block (see product/view/review-quote.phtml). Only
 * exists to give that template a constructor-injected YotpoClient instead
 * of reaching for ObjectManager::getInstance(), while keeping the rest of
 * Magento\Catalog\Block\Product\View's behavior (getProduct(), etc.).
 */
class ReviewQuote extends View
{
    private YotpoClient $yotpoClient;

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
        YotpoClient $yotpoClient,
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
        $this->yotpoClient = $yotpoClient;
    }

    public function getYotpoClient(): YotpoClient
    {
        return $this->yotpoClient;
    }
}
