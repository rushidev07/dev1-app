<?php
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Controller\Index;

use FalcoSense\Search\Helper\Data as SearchHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutFactory;
use Webkul\Marketplace\Helper\Data as MarketplaceHelper;

/**
 * AJAX counterpart to the PDP "more seller items" slider. The slider's
 * data-fetch (a curl_exec to the FalcoSense platform, up to 5s) used to run
 * inline inside the PDP block render, blocking the whole page response. The
 * PDP phtml now renders an empty placeholder instead and calls this action
 * after window 'load', so the fetch happens after the rest of the page is
 * already visible instead of gating it.
 */
class MoreSellerItems extends Action
{
    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly MarketplaceHelper $marketplaceHelper,
        private readonly SearchHelper $searchHelper,
        private readonly LayoutFactory $layoutFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();

        $productId = (int) $this->getRequest()->getParam('product_id');
        if ($productId <= 0) {
            return $resultJson->setData(['success' => false]);
        }

        try {
            $product = $this->productRepository->getById($productId);
        } catch (\Throwable) {
            return $resultJson->setData(['success' => false]);
        }

        $shopTitle = $this->getShopTitle((int) $product->getId());
        if (!$shopTitle) {
            return $resultJson->setData(['success' => false]);
        }

        $brand = '';
        foreach (['product_brand', 'manufacturer'] as $attr) {
            try {
                $val = $product->getAttributeText($attr) ?: $product->getData($attr);
                if ($val && is_string($val) && trim($val) !== '') {
                    $brand = trim($val);
                    break;
                }
            } catch (\Throwable) {
            }
        }

        $categoryName = '';
        if ($brand === '') {
            try {
                foreach ($product->getCategoryCollection()
                    ->addAttributeToSelect('name')
                    ->addIsActiveFilter()
                    ->setPageSize(1) as $cat) {
                    $categoryName = (string) $cat->getName();
                    break;
                }
            } catch (\Throwable) {
            }
        }

        if ($brand === '' && $categoryName === '') {
            return $resultJson->setData(['success' => false]);
        }

        $sliderProducts = $this->fetchSliderProducts($brand, $categoryName, (string) $product->getId());
        if (count($sliderProducts) < 2) {
            return $resultJson->setData(['success' => false]);
        }

        $cfgProducts = array_values(array_filter(
            $sliderProducts,
            static fn($p) => (($p['type'] ?? 'simple') === 'configurable') && (bool) ($p['in_stock'] ?? true)
        ));

        $layout = $this->layoutFactory->create();
        /** @var Template $contentBlock */
        $contentBlock = $layout->createBlock(Template::class)
            ->setTemplate('Ahy_ThemeCustomization::product/view/more-seller-items-slider-content.phtml')
            ->setData('slider_id', 'msi_' . $product->getId())
            ->setData('shop_title', $shopTitle)
            ->setData('slider_products', $sliderProducts)
            ->setData('add_to_cart_url', $this->urlBuilder->getUrl('checkout/cart/add'));

        return $resultJson->setData([
            'success'     => true,
            'html'        => $contentBlock->toHtml(),
            'cfgProducts' => $cfgProducts,
        ]);
    }

    private function getShopTitle(int $productId): string
    {
        $seller = $this->marketplaceHelper->getSellerProductDataByProductId($productId);
        if (!isset($seller->getData()[0]) || !$seller->getData()[0]) {
            return '';
        }

        $sellerObj     = $seller->getData()[0];
        $sellerDataObj = $this->marketplaceHelper->getSellerDataBySellerId($sellerObj['seller_id']);
        $sellerData    = $sellerDataObj->getData()[0];
        $shopTitle     = ($sellerData['shop_title'] ?? '') ?: ($sellerData['name'] ?? '');

        return trim($shopTitle);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSliderProducts(string $brand, string $categoryName, string $currentId): array
    {
        $apiKey       = $this->searchHelper->getApiKey();
        $endpointUrl  = $this->searchHelper->getEndpointUrl();
        $platformBase = rtrim(preg_replace('#/api/v1/ingest/products.*#', '', $endpointUrl), '/')
            ?: 'http://host.docker.internal:8080';

        $qp = ['api_key' => $apiKey, 'limit' => 13, 'sort' => 'popularity'];
        if ($brand !== '') {
            $qp['brand'] = $brand;
        } else {
            $qp['category'] = $categoryName;
        }

        $ch = curl_init($platformBase . '/api/v1/products/collection?' . http_build_query($qp));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $sliderProducts = [];
        if ($code === 200 && $raw) {
            $decoded        = json_decode($raw, true);
            $sliderProducts = $decoded['products'] ?? [];
        }

        return array_values(array_filter(
            $sliderProducts,
            static fn($p) => (string) ($p['id'] ?? '') !== $currentId
        ));
    }
}
