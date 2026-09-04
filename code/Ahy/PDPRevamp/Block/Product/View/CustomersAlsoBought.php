<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\Product\RelatedCarouselDataProvider;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as MpSellerCollectionFactory;

/**
 * PDP "Customers Also Bought" carousel. Looks up the Amasty Mostviewed /
 * Automatic Related Products group named self::GROUP_NAME and exposes
 * product item data including a badge per item.
 *
 * Badge priority: the admin-managed pdp_cab_badge product attribute (see
 * Setup\Patch\Data\CreatePdpCabBadgeAttribute) is multiselect - when an admin
 * has selected one or more values on the product (Stores > Attributes >
 * Product > pdp_cab_badge), they always win and override everything below,
 * and a product can show more than one at once (e.g. both "Trending" and
 * "Essential"). When none are selected, the original automatic badges apply,
 * in this priority order: Best Seller (Magento's own sales bestsellers
 * report) > Top Rated (rating >= 90%) > Same Seller (same marketplace seller
 * as the product currently being viewed) > Under $X (the 2nd-cheapest item
 * in this carousel).
 *
 * Group lookup, bestseller/seller-id/review-summary batching, and the
 * brand-fallback label are shared with AdventureSeekersAlsoViewed via
 * RelatedCarouselDataProvider - see that class for the reasoning.
 */
class CustomersAlsoBought extends Template
{
    public const GROUP_NAME = 'Customers Also Bought';

    /**
     * Maps a pdp_cab_badge option label to the badge's background color.
     * A label with no entry here (e.g. a new option an admin just added
     * via Manage Options) simply renders no badge until a color is added.
     */
    private const CAB_BADGE_COLORS = [
        '#1 Paired Item' => '#E75C26',
        'Trending' => '#16a34a',
        'Essential' => '#0d2f47',
    ];

    private RelatedCarouselDataProvider $dataProvider;
    private ViewModelRegistry $viewModelRegistry;
    private ImageHelper $imageHelper;
    private MpSellerCollectionFactory $mpSellerCollectionFactory;
    private StoreManagerInterface $storeManager;
    private EavConfig $eavConfig;

    /** @var array|null */
    private $items;

    private ?string $currentSellerName = null;
    private bool $currentSellerNameResolved = false;

    /** @var array<string, string>|null */
    private ?array $cabBadgeOptionsById = null;

    public function __construct(
        Context $context,
        RelatedCarouselDataProvider $dataProvider,
        ViewModelRegistry $viewModelRegistry,
        ImageHelper $imageHelper,
        MpSellerCollectionFactory $mpSellerCollectionFactory,
        StoreManagerInterface $storeManager,
        EavConfig $eavConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->dataProvider = $dataProvider;
        $this->viewModelRegistry = $viewModelRegistry;
        $this->imageHelper = $imageHelper;
        $this->mpSellerCollectionFactory = $mpSellerCollectionFactory;
        $this->storeManager = $storeManager;
        $this->eavConfig = $eavConfig;
    }

    /**
     * pdp_cab_badge is a multiselect attribute, so getAttributeText() can't be
     * used to read it - Table::getOptionText() compares the whole stored value
     * ("5,7,9") against each option's single id and never matches, always
     * returning false for a multiselect. Resolving the raw comma-separated ids
     * against this option map (built once, not per product) is what actually
     * works for multiselect.
     *
     * @return array<string, string> option value id => label
     */
    private function getCabBadgeOptionsById(): array
    {
        if ($this->cabBadgeOptionsById === null) {
            $this->cabBadgeOptionsById = [];
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, 'pdp_cab_badge');
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                $this->cabBadgeOptionsById[(string) $option['value']] = (string) $option['label'];
            }
        }

        return $this->cabBadgeOptionsById;
    }

    /**
     * @return string[] the admin-selected badge labels for this product, in
     *                   the order they were selected - empty when none are set.
     */
    private function getCabBadgeLabels(Product $product): array
    {
        $rawValue = (string) $product->getData('pdp_cab_badge');
        if ($rawValue === '') {
            return [];
        }

        $optionsById = $this->getCabBadgeOptionsById();
        $labels = [];
        foreach (explode(',', $rawValue) as $optionId) {
            if (isset($optionsById[$optionId])) {
                $labels[] = $optionsById[$optionId];
            }
        }

        return $labels;
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
     * is store-scoped (prefers the current store's row, falls back to the
     * store_id=0 default) and only trusts shop_title - AdventureSeekersAlso
     * Viewed's version is unscoped and also falls back to the seller's
     * plain name, so unifying them would change one block's behavior.
     *
     * @param int[] $sellerIds
     * @return array<int, string>
     */
    private function getSellerNamesBySellerIds(array $sellerIds): array
    {
        $sellerIds = array_values(array_unique(array_filter(array_map('intval', $sellerIds))));
        if (!$sellerIds) {
            return [];
        }

        $storeId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->mpSellerCollectionFactory->create();
        $collection->addFieldToFilter('seller_id', ['in' => $sellerIds]);
        $collection->addFieldToFilter('store_id', ['in' => [$storeId, 0]]);

        // marketplace_userdata has one row per seller per store (plus a
        // store_id=0 default row) keyed by its own entity_id, not seller_id -
        // collect rows per seller and prefer the current store's row.
        $rowsBySellerId = [];
        foreach ($collection as $seller) {
            $sellerId = (int) $seller->getData('seller_id');
            if (!isset($rowsBySellerId[$sellerId]) || (int) $seller->getData('store_id') === $storeId) {
                $rowsBySellerId[$sellerId] = $seller;
            }
        }

        $namesBySellerId = [];
        foreach ($rowsBySellerId as $sellerId => $seller) {
            $shopTitle = (string) $seller->getData('shop_title');
            if ($shopTitle !== '') {
                $namesBySellerId[$sellerId] = $shopTitle;
            }
        }

        return $namesBySellerId;
    }

    public function getGroupTitle(): ?string
    {
        $group = $this->dataProvider->getGroup(self::GROUP_NAME);
        return $group ? $group->getBlockTitle() : null;
    }

    public function getSubtitle(): string
    {
        $sellerName = $this->getCurrentSellerName();

        return $sellerName
            ? (string) __('Based on purchase data from %1 buyers', $sellerName)
            : (string) __('Based on purchase data from Caliber Nation buyers');
    }

    private function getCurrentSellerName(): ?string
    {
        if ($this->currentSellerNameResolved) {
            return $this->currentSellerName;
        }
        $this->currentSellerNameResolved = true;

        $currentProductId = (int) $this->getCurrentProduct()->getId();
        $sellerIdsByProductId = $this->dataProvider->getSellerIdsByProductIds([$currentProductId]);
        $sellerId = $sellerIdsByProductId[$currentProductId] ?? null;
        if ($sellerId === null) {
            return $this->currentSellerName = null;
        }

        $sellerNamesBySellerId = $this->getSellerNamesBySellerIds([$sellerId]);
        return $this->currentSellerName = $sellerNamesBySellerId[$sellerId] ?? null;
    }

    /**
     * @return Product[]
     */
    private function getProducts(): array
    {
        $group = $this->dataProvider->getGroup(self::GROUP_NAME);
        $products = $group ? $this->dataProvider->getProducts($group, $this->getCurrentProduct()) : [];

        if ($products) {
            return $products;
        }

        // Amasty has nothing yet for this product - fall back to the
        // admin-picked "Customers Also Bought (Manual Fallback)" grid on
        // the product edit page (see Ui\DataProvider\Product\Form\Modifier\
        // ManualCarousels). Never overrides real Amasty data, only fills
        // the gap when there is none.
        return $this->dataProvider->getManualFallbackProducts(
            $this->getCurrentProduct(),
            CreateManualCarouselLinkTypes::LINK_TYPE_MANUAL_CAB
        );
    }

    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $products = $this->getProducts();
        $bestsellerIds = $this->dataProvider->getBestsellerProductIds();

        $productIds    = array_map(static fn (Product $p): int => (int) $p->getId(), $products);
        $reviewSummaries = $this->dataProvider->getReviewSummaryByProductIds($productIds);

        $currentProductId = (int) $this->getCurrentProduct()->getId();
        $sellerIdsByProductId = $this->dataProvider->getSellerIdsByProductIds(array_merge(
            [$currentProductId],
            $productIds
        ));
        $currentSellerId = $sellerIdsByProductId[$currentProductId] ?? null;
        $sellerNamesBySellerId = $this->getSellerNamesBySellerIds(array_values($sellerIdsByProductId));

        $rawItems = [];
        foreach ($products as $product) {
            /** @var Product $product */
            if (!$product->isSaleable()) {
                continue;
            }

            $finalPrice    = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            $regularPrice  = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();

            $requiredOptions  = (bool) $product->getTypeInstance()->hasRequiredOptions($product);
            $pid              = (int) $product->getId();
            $reviewData       = $reviewSummaries[$pid] ?? null;
            $ratingSummary    = $reviewData ? $reviewData['rating'] : 0.0;
            $reviewsCount     = $reviewData ? $reviewData['count'] : 0;
            $sid              = $sellerIdsByProductId[$pid] ?? null;
            $isBestseller     = isset($bestsellerIds[$pid]);
            $isTopRated       = $ratingSummary >= 90.0;
            $isSameSeller     = $currentSellerId !== null && $sid === $currentSellerId;
            $badgeOverrides   = $this->getCabBadgeLabels($product);

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
                'isDirectlyAddable' => $product->getTypeId() === 'simple'
                    && $product->isSaleable()
                    && !$requiredOptions,
                'isConfigurable'  => $product->getTypeId() === 'configurable' && $product->isSaleable(),
                'addToCartUrl'    => $this->getUrl('checkout/cart/add', [
                    '_secure' => true,
                    'product' => $product->getId(),
                ]),
                'isBestseller'    => $isBestseller,
                'isTopRated'      => $isTopRated,
                'isSameSeller'    => $isSameSeller,
                'badgeOverride'   => $badgeOverrides,
                'sellerName'      => $this->dataProvider->resolveDisplayBrand($product, $sid ? ($sellerNamesBySellerId[$sid] ?? null) : null),
            ];
        }

        // Under $X badge: target the 2nd-cheapest item in this grid.
        $sortedByPrice = $rawItems;
        usort($sortedByPrice, fn (array $a, array $b) => $a['finalPrice'] <=> $b['finalPrice']);
        $underPriceTargetId  = isset($sortedByPrice[1]) ? $sortedByPrice[1]['id'] : null;
        $underPriceBadgeValue = $underPriceTargetId !== null
            ? (int) floor($sortedByPrice[1]['finalPrice']) + 1
            : null;

        $this->items = [];
        foreach ($rawItems as $item) {
            if (!empty($item['badgeOverride'])) {
                // Any admin-selected pdp_cab_badge values always override the
                // automatic badges below for this product - a product can
                // show more than one at once (e.g. both "Trending" and
                // "Essential"). A selected label with no color entry in
                // CAB_BADGE_COLORS is silently dropped rather than shown.
                $badges = [];
                foreach ($item['badgeOverride'] as $label) {
                    if (isset(self::CAB_BADGE_COLORS[$label])) {
                        $badges[] = ['label' => $label, 'color' => self::CAB_BADGE_COLORS[$label]];
                    }
                }
            } else {
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
                if ($item['id'] === $underPriceTargetId) {
                    $badges[] = ['label' => (string) __('Under $%1', $underPriceBadgeValue), 'color' => '#16a34a'];
                }
            }

            unset($item['isBestseller'], $item['isTopRated'], $item['isSameSeller'], $item['badgeOverride']);
            $item['badges'] = $badges;
            $this->items[]  = $item;
        }

        return $this->items;
    }
}
