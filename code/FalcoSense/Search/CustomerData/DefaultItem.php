<?php
namespace FalcoSense\Search\CustomerData;

use FalcoSense\Search\Helper\ProductImage;

class DefaultItem extends \Magento\Checkout\CustomerData\DefaultItem
{
    private ProductImage $productImageHelper;

    public function __construct(
        \Magento\Catalog\Helper\Image $imageHelper,
        \Magento\Msrp\Helper\Data $msrpHelper,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Catalog\Helper\Product\ConfigurationPool $configurationPool,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        ProductImage $productImageHelper,
        \Magento\Framework\Escaper $escaper = null,
        \Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface $itemResolver = null
    ) {
        parent::__construct(
            $imageHelper, $msrpHelper, $urlBuilder,
            $configurationPool, $checkoutHelper, $escaper, $itemResolver
        );
        $this->productImageHelper = $productImageHelper;
    }

    protected function doGetItemData()
    {
        $data = parent::doGetItemData();

        $sku = $this->item->getProduct()->getSku();
        $url = $this->productImageHelper->getUrlBySku($sku);

        if ($url) {
            $data['product_image']['src'] = $url;
        }

        return $data;
    }
}
