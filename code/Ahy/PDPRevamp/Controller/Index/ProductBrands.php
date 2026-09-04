<?php

namespace Ahy\PDPRevamp\Controller\Index;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;

/**
 * Resolves a label for a list of SKUs, server side, for the "Recently
 * Viewed" carousel: the "product_brand" attribute's option label if the
 * product has one (this exists only because Magento's GraphQL
 * "custom_attributes" field isn't available on this install - querying it
 * via GraphQL doesn't just fail cleanly, it crashes the query validator
 * instead), otherwise the marketplace seller's shop name, otherwise
 * "Everest" - the same three-tier fallback the PDP's own Seller Info tab
 * already uses (details-seller-info.phtml), so a product missing brand data
 * doesn't just show nothing here while the PDP itself says "Sold and
 * shipped by Everest."
 */
class ProductBrands implements HttpGetActionInterface
{
    private Context $context;
    private JsonFactory $resultJsonFactory;
    private ProductRepositoryInterface $productRepository;
    private MarketplaceHelper $marketplaceHelper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        MarketplaceHelper $marketplaceHelper
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->productRepository = $productRepository;
        $this->marketplaceHelper = $marketplaceHelper;
    }

    public function execute(): ResultInterface
    {
        $request = $this->context->getRequest();
        $skus = $request->getParam('skus', []);
        $result = $this->resultJsonFactory->create();

        if (!is_array($skus) || !$skus) {
            return $result->setData([]);
        }

        $brands = [];
        foreach (array_slice($skus, 0, 50) as $sku) {
            $sku = (string) $sku;
            if ($sku === '') {
                continue;
            }

            try {
                $product = $this->productRepository->get($sku, false, null, true);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            $brands[$sku] = $this->resolveLabel($product);
        }

        return $result->setData($brands);
    }

    private function resolveLabel(\Magento\Catalog\Model\Product $product): string
    {
        $brand = (string) $product->getAttributeText('product_brand');
        if ($brand !== '' && $brand !== 'No') {
            return $brand;
        }

        $seller = $this->marketplaceHelper->getSellerProductDataByProductId((int) $product->getId());
        $sellerRow = $seller->getData()[0] ?? null;
        if ($sellerRow) {
            $sellerData = $this->marketplaceHelper->getSellerDataBySellerId((int) $sellerRow['seller_id'])->getData()[0] ?? [];
            $shopTitle = (string) ($sellerData['shop_title'] ?? '');
            if ($shopTitle !== '') {
                return $shopTitle;
            }
        }

        return 'Everest';
    }
}
