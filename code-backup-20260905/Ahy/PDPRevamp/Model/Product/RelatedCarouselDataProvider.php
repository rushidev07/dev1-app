<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product;

use Amasty\Mostviewed\Api\Data\GroupInterface;
use Amasty\Mostviewed\Api\GroupRepositoryInterface;
use Amasty\Mostviewed\Model\ProductProvider;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\LinkFactory as ProductLinkModelFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Review\Model\ResourceModel\Review\Summary\CollectionFactory as ReviewSummaryCollectionFactory;
use Magento\Sales\Model\ResourceModel\Report\Bestsellers\CollectionFactory as BestsellersCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Webkul\Marketplace\Model\ResourceModel\Product\CollectionFactory as MpProductCollectionFactory;

/**
 * Shared lookups used by the PDP's Amasty-Mostviewed-backed carousels
 * (CustomersAlsoBought, AdventureSeekersAlsoViewed): group resolution,
 * the applied-products collection, seller-id batching, review-summary
 * batching, and the bestseller-id set. Extracted so both blocks stay in
 * sync instead of maintaining two copies of the same lookups.
 *
 * Each method memoizes within this instance. Since Magento shares one
 * instance of a plain class per request by default, this also means the
 * unbounded bestseller collection now loads once per request instead of
 * once per carousel when both render on the same page.
 *
 * getSellerNamesBySellerIds() is deliberately NOT here: the two blocks'
 * versions differ (store-scoped seller_id lookup vs unscoped entity_id
 * lookup with a name fallback), and unifying them would change one
 * block's behavior, so each keeps its own copy.
 */
class RelatedCarouselDataProvider
{
    private GroupRepositoryInterface $groupRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private ProductProvider $productProvider;
    private BestsellersCollectionFactory $bestsellersCollectionFactory;
    private MpProductCollectionFactory $mpProductCollectionFactory;
    private ReviewSummaryCollectionFactory $reviewSummaryCollectionFactory;
    private StoreManagerInterface $storeManager;
    private ProductLinkModelFactory $productLinkModelFactory;

    /** @var array<string, GroupInterface|false> */
    private array $groupsByName = [];

    /** @var array<int, true>|null */
    private ?array $bestsellerProductIds = null;

    public function __construct(
        GroupRepositoryInterface $groupRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ProductProvider $productProvider,
        BestsellersCollectionFactory $bestsellersCollectionFactory,
        MpProductCollectionFactory $mpProductCollectionFactory,
        ReviewSummaryCollectionFactory $reviewSummaryCollectionFactory,
        StoreManagerInterface $storeManager,
        ProductLinkModelFactory $productLinkModelFactory
    ) {
        $this->groupRepository = $groupRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->productProvider = $productProvider;
        $this->bestsellersCollectionFactory = $bestsellersCollectionFactory;
        $this->mpProductCollectionFactory = $mpProductCollectionFactory;
        $this->reviewSummaryCollectionFactory = $reviewSummaryCollectionFactory;
        $this->storeManager = $storeManager;
        $this->productLinkModelFactory = $productLinkModelFactory;
    }

    /**
     * Admin-picked fallback products for a manual link type (see
     * Setup\Patch\Data\CreateManualCarouselLinkTypes) - used only when
     * Amasty's automatic getProducts() above has nothing for this product,
     * never as an override of real Amasty data. Ordered by the position set
     * in the product-picker grid on the product edit page.
     *
     * Reads via the older, generic Model\Product\Link mechanism (any
     * link_type_id, straight SQL join on catalog_product_link) rather than
     * ProductLinkRepositoryInterface::getList(), which only recognizes link
     * types registered with Magento\Catalog\Model\ProductLink\
     * CollectionProvider - a second hardcoded registry beyond
     * LinkTypeProvider that a custom link type would also need to satisfy.
     *
     * @return Product[]
     */
    public function getManualFallbackProducts(Product $currentProduct, int $linkTypeId): array
    {
        try {
            $linkModel = $this->productLinkModelFactory->create();
            $linkModel->setLinkTypeId($linkTypeId);

            $collection = $linkModel->getProductCollection();
            $collection->setProduct($currentProduct);
            $collection->addAttributeToSelect(['name', 'price', 'special_price', 'image', 'small_image']);
            $collection->setPositionOrder();

            return array_values(iterator_to_array($collection));
        } catch (\Exception $e) {
            // This is a fallback for when Amasty has nothing - it must never
            // take the whole PDP down if the product-link read itself fails.
            return [];
        }
    }

    /**
     * @return GroupInterface|false
     */
    public function getGroup(string $groupName)
    {
        if (array_key_exists($groupName, $this->groupsByName)) {
            return $this->groupsByName[$groupName];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(GroupInterface::GROUP_NAME, $groupName)
            ->create();
        $matches = $this->groupRepository->getList($searchCriteria)->getItems();
        $group = reset($matches);
        $group = $group ?: false;
        if ($group && !$group->getStatus()) {
            $group = false;
        }

        return $this->groupsByName[$groupName] = $group;
    }

    /**
     * @return Product[]
     */
    public function getProducts(GroupInterface $group, Product $currentProduct): array
    {
        $collection = $this->productProvider->getAppliedProducts($group, $currentProduct);
        if (!$collection) {
            return [];
        }

        $collection->setPageSize($group->getMaxProducts());
        $this->productProvider->prepareCollection($group, $collection, (int) $currentProduct->getId());

        return array_values(iterator_to_array($collection));
    }

    /**
     * @return array<int, true>
     */
    public function getBestsellerProductIds(): array
    {
        if ($this->bestsellerProductIds !== null) {
            return $this->bestsellerProductIds;
        }

        /** @var \Magento\Sales\Model\ResourceModel\Report\Bestsellers\Collection $collection */
        $collection = $this->bestsellersCollectionFactory->create();
        $collection->load();

        $ids = [];
        foreach ($collection as $row) {
            $ids[(int) $row->getData('product_id')] = true;
        }

        return $this->bestsellerProductIds = $ids;
    }

    /**
     * @param int[] $productIds
     * @return array<int, int>
     */
    public function getSellerIdsByProductIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if (!$productIds) {
            return [];
        }

        $collection = $this->mpProductCollectionFactory->create();
        $collection->addFieldToFilter('mageproduct_id', ['in' => $productIds]);

        $sellerIdsByProductId = [];
        foreach ($collection as $sellerProduct) {
            $sellerIdsByProductId[(int) $sellerProduct->getData('mageproduct_id')]
                = (int) $sellerProduct->getData('seller_id');
        }

        return $sellerIdsByProductId;
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{rating: float, count: int}>
     */
    public function getReviewSummaryByProductIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $storeId    = (int) $this->storeManager->getStore()->getId();
        $collection = $this->reviewSummaryCollectionFactory->create();
        $collection->addFieldToFilter('entity_pk_value', ['in' => $productIds]);
        $collection->addFieldToFilter('store_id', $storeId);

        $result = [];
        foreach ($collection as $summary) {
            $count = (int) $summary->getData('reviews_count');
            if ($count > 0) {
                $result[(int) $summary->getData('entity_pk_value')] = [
                    'rating' => (float) $summary->getData('rating_summary'),
                    'count'  => $count,
                ];
            }
        }

        return $result;
    }

    /**
     * Label shown under the product name: the "product_brand" attribute's
     * label if the product has one, otherwise the marketplace seller's shop
     * name, otherwise "Everest" - same three-tier fallback used by both
     * carousels so a card without brand/seller data doesn't just show a
     * blank line while every other card has one.
     */
    public function resolveDisplayBrand(Product $product, ?string $sellerName): string
    {
        $brand = (string) $product->getAttributeText('product_brand');
        if ($brand !== '' && $brand !== 'No') {
            return $brand;
        }

        if ($sellerName !== null && $sellerName !== '') {
            return $sellerName;
        }

        return (string) __('Everest');
    }
}
