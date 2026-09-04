<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Block\Brands;

use Ahy\PlpRevamp\Model\FeaturedBrandsParser;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class AllBrands extends Template
{
    private const BRAND_ATTRIBUTES = ['ahy_featured_brands', 'ahy_featured_brand_names'];

    /** @var array<int, array{label: string, url: string, image: string}>|null */
    private ?array $brandsCache = null;

    public function __construct(
        Template\Context $context,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly FeaturedBrandsParser $brandsParser,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EavConfig $eavConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Aggregates every brand configured across active categories (via `ahy_featured_brands`, with a
     * fallback to the legacy `ahy_featured_brand_names`), de-duplicated by label and sorted A–Z.
     *
     * @return array<int, array{label: string, url: string, image: string}>
     */
    public function getAllBrands(): array
    {
        if ($this->brandsCache !== null) {
            return $this->brandsCache;
        }

        // Only reference attributes that actually exist — the module may be running before
        // setup:upgrade has created ahy_featured_brands. Referencing a missing attribute in a
        // collection throws "attribute name is invalid", so degrade gracefully instead.
        $attributes = array_values(array_filter(
            self::BRAND_ATTRIBUTES,
            fn(string $code): bool => (bool)$this->eavConfig->getAttribute(Category::ENTITY, $code)->getId()
        ));

        if ($attributes === []) {
            return $this->brandsCache = [];
        }

        $storeId      = (int)$this->storeManager->getStore()->getId();
        $mediaBaseUrl = rtrim((string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
        $webBaseUrl   = rtrim((string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect($attributes)
            ->addAttributeToFilter('is_active', 1);
            // ->addAttributeToFilter(
            //     array_map(static fn(string $code): array => ['attribute' => $code, 'notnull' => true], $attributes)
            // );

        $seen   = [];
        $brands = [];

        foreach ($collection as $category) {
            $rows = $this->brandsParser->parse($category->getData('ahy_featured_brands'));
            if ($rows === []) {
                $rows = $this->brandsParser->parseLegacy((string)$category->getData('ahy_featured_brand_names'));
            }

            foreach ($rows as $row) {
                $key = mb_strtolower($row['label']);
                if (isset($seen[$key])) {
                    // First occurrence wins; fill in an image if the earlier one lacked one.
                    if ($brands[$seen[$key]]['image'] === '' && $row['image'] !== '') {
                        $brands[$seen[$key]]['image'] =
                            $this->brandsParser->buildImageUrl($row['image'], $mediaBaseUrl, $webBaseUrl);
                    }
                    continue;
                }

                $seen[$key] = count($brands);
                $brands[]   = [
                    'label' => $row['label'],
                    'url'   => $row['url'],
                    'image' => $this->brandsParser->buildImageUrl($row['image'], $mediaBaseUrl, $webBaseUrl),
                ];
            }
        }

        usort($brands, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        $this->brandsCache = array_values($brands);
        return $this->brandsCache;
    }

    public function getBrandCount(): int
    {
        return count($this->getAllBrands());
    }

    /**
     * Returns the full URL of the all-brands banner image, or empty string if not set.
     */
    public function getBannerUrl(): string
    {
        $value = (string)$this->scopeConfig->getValue(
            'ahy_plprevamp/all_brands/banner_image',
            ScopeInterface::SCOPE_STORE
        );

        if ($value === '') {
            return '';
        }

        $mediaBaseUrl = rtrim((string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
        return $mediaBaseUrl . '/ahy_plprevamp/banner/' . ltrim($value, '/');
    }

    /**
     * Everest logo shown when a brand has no image of its own — the same asset and
     * path SubcategoryCards::getFallbackImageUrl() uses, so both surfaces fall back
     * to one image.
     */
    public function getFallbackImageUrl(): string
    {
        $mediaBaseUrl = rtrim((string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
        return $mediaBaseUrl . '/ahy_plp/everest-fallback-logo.png';
    }
}
