<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Block\Category;

use Magento\Catalog\Block\Category\View;
use Magento\Catalog\Helper\Category as CategoryHelper;
use Magento\Catalog\Model\Layer\Resolver;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class ProductGrid extends View
{
    private const PAGE_SIZE = 24;

    private ?Collection $productCollection = null;
    private ?int $totalCount = null;

    public function __construct(
        Context $context,
        Resolver $layerResolver,
        Registry $registry,
        CategoryHelper $categoryHelper,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Visibility $productVisibility,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $layerResolver, $registry, $categoryHelper, $data);
    }

    public function getProductCollection(): Collection
    {
        if ($this->productCollection !== null) {
            return $this->productCollection;
        }

        $category = $this->getCurrentCategory();
        [$sortBy, $sortDir] = $this->resolveSortParams();

        $collection = $this->collectionFactory->create();
        $collection
            ->addAttributeToSelect([
                'name', 'price', 'special_price', 'small_image',
                'url_key', 'type_id', 'shop_title',
            ])
            ->addCategoryFilter($category)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->setVisibility($this->productVisibility->getVisibleInCatalogIds())
            ->addMinimalPrice()
            ->addFinalPrice()
            ->joinField(
                'is_in_stock',
                'cataloginventory_stock_item',
                'is_in_stock',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            )
            ->joinField(
                'qty',
                'cataloginventory_stock_item',
                'qty',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'left'
            )
            ->addFieldToFilter('is_in_stock', ['eq' => 1])
            ->setPageSize(self::PAGE_SIZE)
            ->setCurPage($this->getCurrentPage())
            ->setOrder($sortBy, $sortDir);

        $this->productCollection = $collection;
        return $collection;
    }

    private function resolveSortParams(): array
    {
        $sort = $this->getRequest()->getParam('product_list_order', 'position');
        return match ($sort) {
            'price_asc'  => ['price', 'ASC'],
            'price_desc' => ['price', 'DESC'],
            'name'       => ['name', 'ASC'],
            'newest'     => ['entity_id', 'DESC'],
            default      => ['position', 'ASC'],
        };
    }

    public function getCurrentPage(): int
    {
        return max(1, (int)$this->getRequest()->getParam('p', 1));
    }

    public function getTotalCount(): int
    {
        if ($this->totalCount === null) {
            $this->totalCount = (int)$this->getProductCollection()->getSize();
        }
        return $this->totalCount;
    }

    public function getTotalPages(): int
    {
        return (int)ceil($this->getTotalCount() / self::PAGE_SIZE);
    }

    public function getPageSize(): int
    {
        return self::PAGE_SIZE;
    }

    public function getMediaBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }

    public function getProductImageUrl(\Magento\Catalog\Model\Product $product): string
    {
        $image = $product->getSmallImage();
        if (!$image || $image === 'no_selection') {
            return '';
        }
        return 'https://static.everest.com/media/catalog/product' . $image;
    }

    public function hasDiscount(\Magento\Catalog\Model\Product $product): bool
    {
        $price      = (float)$product->getPrice();
        $finalPrice = (float)$product->getFinalPrice();
        // Configurable/grouped products have price=0 — no discount badge
        return $price > 0 && $finalPrice > 0 && $finalPrice < $price;
    }

    public function getSavingsPercent(\Magento\Catalog\Model\Product $product): int
    {
        $price      = (float)$product->getPrice();
        $finalPrice = (float)$product->getFinalPrice();
        if ($price <= 0) {
            return 0;
        }
        return (int)round((($price - $finalPrice) / $price) * 100);
    }

    public function getDisplayPrice(\Magento\Catalog\Model\Product $product): float
    {
        $price      = (float)$product->getPrice();
        $finalPrice = (float)$product->getFinalPrice();
        $minPrice   = (float)$product->getData('min_price');

        // Configurable/grouped: getPrice()=0, use min_price from price index
        if ($price <= 0) {
            return $minPrice > 0 ? $minPrice : $finalPrice;
        }

        return ($finalPrice > 0 && $finalPrice < $price) ? $finalPrice : $price;
    }

    public function isInStock(\Magento\Catalog\Model\Product $product): bool
    {
        return (bool)$product->getData('is_in_stock');
    }

    public function getStockQty(\Magento\Catalog\Model\Product $product): int
    {
        return (int)$product->getData('qty');
    }

    public function getSellerName(\Magento\Catalog\Model\Product $product): string
    {
        return (string)($product->getData('shop_title') ?: '');
    }

    public function isSimple(\Magento\Catalog\Model\Product $product): bool
    {
        return $product->getTypeId() === 'simple';
    }

    public function getAddToCartUrl(\Magento\Catalog\Model\Product $product): string
    {
        return $this->getUrl('checkout/cart/add', [
            '_secure' => true,
            'product' => $product->getId(),
        ]);
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function getPagerUrl(int $page): string
    {
        $params       = $this->getRequest()->getParams();
        $params['p']  = $page;
        unset($params['id'], $params['category']);
        return $this->getUrl('*/*/*', ['_current' => true, '_use_rewrite' => true, '_query' => $params]);
    }

    public function getCurrentSortOrder(): string
    {
        return (string)$this->getRequest()->getParam('product_list_order', 'position');
    }
}
