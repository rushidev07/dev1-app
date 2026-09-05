<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\Product\RelatedCarouselDataProvider;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as MpSellerCollectionFactory;

/**
 * Badge overrides: when an admin hasn't entered a value in Stores >
 * Configuration > PDP Badges, each badge falls back to its original
 * automatic behavior. Best Seller falls back to Magento's own sales
 * bestsellers report; Under $X falls back to flagging the 2nd-cheapest
 * item in this carousel. Setting a seller / a dollar value in config
 * overrides that automatic behavior for as long as the value is set.
 *
 * Group lookup, bestseller/seller-id/review-summary batching, and the
 * brand-fallback label are shared with CustomersAlsoBought via
 * RelatedCarouselDataProvider - see that class for the reasoning.
 */
class AdventureSeekersAlsoViewed extends Template
{
    public const GROUP_NAME = 'Adventure Seekers Also Viewed';

    private const XML_PATH_BEST_SELLER_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/best_seller_enabled';
    private const XML_PATH_BEST_SELLER_NAME = 'pdprevamp_pdp_badges/adventure_seekers/best_seller_name';
    private const XML_PATH_TOP_RATED_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/top_rated_enabled';
    private const XML_PATH_TOP_RATED_THRESHOLD = 'pdprevamp_pdp_badges/adventure_seekers/top_rated_threshold';
    private const XML_PATH_SAME_SELLER_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/same_seller_enabled';
    private const XML_PATH_UNDER_PRICE_ENABLED = 'pdprevamp_pdp_badges/adventure_seekers/under_price_enabled';
    private const XML_PATH_UNDER_PRICE_VALUE = 'pdprevamp_pdp_badges/adventure_seekers/under_price_value';

    private RelatedCarouselDataProvider $dataProvider;
    private ViewModelRegistry $viewModelRegistry;
    private ImageHelper $imageHelper;
    private MpSellerCollectionFactory $mpSellerCollectionFactory;

    /** @var array|null */
    private $items;

    public function __construct(
        Context $context,
        RelatedCarouselDataProvider $dataProvider,
        ViewModelRegistry $viewModelRegistry,
        ImageHelper $imageHelper,
        MpSellerCollectionFactory $mpSellerCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->dataProvider = $dataProvider;
        $this->viewModelRegistry = $viewModelRegistry;
        $this->imageHelper = $imageHelper;
        $this->mpSellerCollectionFactory = $mpSellerCollectionFactory;
    }

    private function isBestSellerEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_BEST_SELLER_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function getBestSellerName(): ?string
    {
        $value = trim((string) $this->_scopeConfig->getValue(self::XML_PATH_BEST_SELLER_NAME, ScopeInterface::SCOPE_STORE));
        return $value !== '' ? $value : null;
    }

    /**
     * Resolves the admin-picked seller name (Stores > Configuration > AHY >
     * PDP Badges - a dropdown of real sellers, see Model\Config\Source\
     * SellerNames) to the marketplace seller entity id it matches, so the
     * same id-based comparison used everywhere else in this class still
     * works. marketplace_userdata has no "name" column - shop_title is the
     * only real display-name field on that table - so matching is
     * case-insensitive against shop_title only. Null when nothing matches
     * (falls back to the automatic bestseller behavior, same as an empty
     * field).
     */
    private function resolveSellerIdByName(string $name): ?int
    {
        $collection = $this->mpSellerCollectionFactory->create();
        $collection->addFieldToFilter('shop_title', ['eq' => $name]);

        foreach ($collection as $seller) {
            $shopTitle = trim((string) $seller->getData('shop_title'));
            if (strcasecmp($shopTitle, $name) === 0) {
                return (int) $seller->getData('entity_id');
            }
        }

        return null;
    }

    private function isTopRatedEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_TOP_RATED_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function getTopRatedThreshold(): float
    {
        $value = (float) $this->_scopeConfig->getValue(self::XML_PATH_TOP_RATED_THRESHOLD, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : 90.0;
    }

    private function isSameSellerEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_SAME_SELLER_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    private function isUnderPriceEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_PATH_UNDER_PRICE_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Null when an admin hasn't entered a value - the caller should then
     * fall back to the automatic "2nd-cheapest item" behavior instead of a
     * fixed dollar threshold.
     */
    private function getUnderPriceValue(): ?float
    {
        $value = $this->_scopeConfig->getValue(self::XML_PATH_UNDER_PRICE_VALUE, ScopeInterface::SCOPE_STORE);
        if ($value === null || $value === '') {
            return null;
        }
        $floatValue = (float) $value;
        return $floatValue > 0 ? $floatValue : null;
    }

    /**
     * "Under $5" for a whole-number threshold, "Under $4.99" for a
     * fractional one - avoids an admin-entered "5" rendering as "Under $5.00".
     */
    private function formatUnderPriceLabel(float $value): string
    {
        $formatted = fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 2);
        return (string) __('Under $%1', $formatted);
    }

    private function getCurrentProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    /**
     * Batch shop-title lookup for a set of seller (customer entity) ids.
     *
     * Kept local rather than in RelatedCarouselDataProvider: this version
     * is unscoped by store and falls back to the seller's plain name -
     * CustomersAlsoBought's version is store-scoped and only trusts
     * shop_title, so unifying them would change one block's behavior.
     */
    private function getSellerNamesBySellerIds(array $sellerIds): array
    {
        $sellerIds = array_values(array_unique(array_filter(array_map('intval', $sellerIds))));
        if (!$sellerIds) {
            return [];
        }

        $collection = $this->mpSellerCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $sellerIds]);

        $namesBySellerId = [];
        foreach ($collection as $seller) {
            $shopTitle = (string) $seller->getData('shop_title');
            $name      = (string) $seller->getData('name');
            $display   = $shopTitle !== '' ? $shopTitle : ($name !== '' ? $name : null);
            if ($display !== null) {
                $namesBySellerId[(int) $seller->getData('entity_id')] = $display;
            }
        }

        return $namesBySellerId;
    }

    public function getGroupTitle(): ?string
    {
        $group = $this->dataProvider->getGroup(self::GROUP_NAME);
        return $group ? $group->getBlockTitle() : null;
    }

    private function getProducts(): array
    {
        $group = $this->dataProvider->getGroup(self::GROUP_NAME);
        $products = $group ? $this->dataProvider->getProducts($group, $this->getCurrentProduct()) : [];

        if ($products) {
            return $products;
        }

        // Amasty has nothing yet for this product - fall back to the
        // admin-picked "Adventure Seekers Also Viewed (Manual Fallback)"
        // grid on the product edit page (see Ui\DataProvider\Product\Form\
        // Modifier\ManualCarousels). Never overrides real Amasty data,
        // only fills the gap when there is none.
        return $this->dataProvider->getManualFallbackProducts(
            $this->getCurrentProduct(),
            CreateManualCarouselLinkTypes::LINK_TYPE_MANUAL_ASAV
        );
    }

    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $products = $this->getProducts();
        $bestsellerIds = $this->dataProvider->getBestsellerProductIds();

        $productIds      = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
        $reviewSummaries = $this->dataProvider->getReviewSummaryByProductIds($productIds);

        $currentProductId     = (int) $this->getCurrentProduct()->getId();
        $sellerIdsByProductId = $this->dataProvider->getSellerIdsByProductIds(array_merge(
            [$currentProductId],
            $productIds
        ));
        $currentSellerId       = $sellerIdsByProductId[$currentProductId] ?? null;
        $sellerNamesBySellerId = $this->getSellerNamesBySellerIds(array_values($sellerIdsByProductId));

        $bestSellerEnabled       = $this->isBestSellerEnabled();
        $bestSellerName          = $bestSellerEnabled ? $this->getBestSellerName() : null;
        $bestSellerSellerId      = $bestSellerName !== null ? $this->resolveSellerIdByName($bestSellerName) : null;
        $topRatedEnabled         = $this->isTopRatedEnabled();
        $topRatedThreshold       = $this->getTopRatedThreshold();
        $sameSellerEnabled       = $this->isSameSellerEnabled();
        $underPriceEnabled       = $this->isUnderPriceEnabled();
        $underPriceConfigValue   = $underPriceEnabled ? $this->getUnderPriceValue() : null;

        $rawItems = [];
        foreach ($products as $product) {
            /** @var Product $product */
            if (!$product->isSaleable()) {
                continue;
            }

            $pid          = (int) $product->getId();
            $finalPrice   = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $regularPrice = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
            $requiredOptions = (bool) $product->getTypeInstance()->hasRequiredOptions($product);

            $reviewData    = $reviewSummaries[$pid] ?? null;
            $ratingSummary = $reviewData ? $reviewData['rating'] : 0.0;
            $reviewsCount  = $reviewData ? $reviewData['count'] : 0;
            $sid           = $sellerIdsByProductId[$pid] ?? null;

            // Best Seller: an admin-selected seller overrides Magento's own
            // sales bestsellers report; without one, fall back to it.
            $isBestseller = $bestSellerEnabled && ($bestSellerSellerId !== null
                ? $sid === $bestSellerSellerId
                : isset($bestsellerIds[$pid]));
            $isTopRated   = $topRatedEnabled && $ratingSummary >= $topRatedThreshold;
            $isSameSeller = $sameSellerEnabled && $currentSellerId !== null && $sid === $currentSellerId;

            $rawItems[] = [
                'id'              => $pid,
                'sku'             => $product->getSku(),
                'name'            => html_entity_decode((string) $product->getName(), ENT_QUOTES),
                'image'           => $this->imageHelper
                    ->init($product, 'ahy_adventure_seekers_thumbnail')
                    ->setImageFile($product->getData('small_image'))
                    ->getUrl(),
                'finalPrice'      => round($finalPrice, 2),
                'regularPrice'    => round($regularPrice, 2),
                'hasDiscount'     => $regularPrice > $finalPrice,
                'url'             => $product->getProductUrl(),
                'ratingSummary'   => $ratingSummary,
                'reviewsCount'    => $reviewsCount,
                'isConfigurable'  => $product->getTypeId() === 'configurable' && $product->isSaleable(),
                'addToCartUrl'    => $this->getUrl('checkout/cart/add', [
                    '_secure' => true,
                    'product' => $product->getId(),
                ]),
                'isBestseller'    => $isBestseller,
                'isTopRated'      => $isTopRated,
                'isSameSeller'    => $isSameSeller,
                'sellerName'      => $this->dataProvider->resolveDisplayBrand($product, $sid ? ($sellerNamesBySellerId[$sid] ?? null) : null),
            ];
        }

        // Under $X: an admin-entered value flags every item priced below
        // it. Without one, fall back to flagging just the 2nd-cheapest
        // item in this carousel (the original automatic behavior).
        $underPriceTargetId = null;
        $underPriceAutoLabel = null;
        if ($underPriceEnabled && $underPriceConfigValue === null) {
            $sortedByPrice = $rawItems;
            usort($sortedByPrice, fn (array $a, array $b) => $a['finalPrice'] <=> $b['finalPrice']);
            if (isset($sortedByPrice[1])) {
                $underPriceTargetId = $sortedByPrice[1]['id'];
                $underPriceAutoLabel = $this->formatUnderPriceLabel((float) floor($sortedByPrice[1]['finalPrice']) + 1);
            }
        }

        $this->items = [];
        foreach ($rawItems as $item) {
            $isUnderPrice = false;
            $underPriceLabel = null;
            if ($underPriceEnabled) {
                if ($underPriceConfigValue !== null) {
                    $isUnderPrice = $item['finalPrice'] < $underPriceConfigValue;
                    $underPriceLabel = $this->formatUnderPriceLabel($underPriceConfigValue);
                } elseif ($item['id'] === $underPriceTargetId) {
                    $isUnderPrice = true;
                    $underPriceLabel = $underPriceAutoLabel;
                }
            }

            $badges = [];
            if ($item['isBestseller']) {
                $badges[] = ['label' => (string) __('Best Seller'), 'color' => '#E75C26'];
            }
            if ($item['isTopRated']) {
                $badges[] = ['label' => (string) __('Top Rated'), 'color' => '#E75C26'];
            }
            if ($item['isSameSeller']) {
                $badges[] = ['label' => (string) __('Same Seller'), 'color' => '#0d2f47'];
            }
            if ($isUnderPrice) {
                $badges[] = ['label' => $underPriceLabel, 'color' => '#2D7AF4'];
            }

            unset($item['isBestseller'], $item['isTopRated'], $item['isSameSeller']);
            $item['badges'] = $badges;
            $this->items[]  = $item;
        }

        return $this->items;
    }
}
