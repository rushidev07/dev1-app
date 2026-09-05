<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Model\ResourceModel\ProductAffinity;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateFbtForceManualAttribute;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualFbtLinkType;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides which products appear beside the current one in the PDP's
 * "Frequently Bought Together" section.
 *
 * Two tiers, in this order:
 *
 *   1. Co-purchase - products actually bought alongside this one, from order
 *      history (Model\ResourceModel\ProductAffinity).
 *   2. Manual - products an admin picked on the product edit page, via link type
 *      92 (Setup\Patch\Data\CreateManualFbtLinkType).
 *
 * If neither yields anything the caller gets an empty array and the section is not
 * rendered at all. That is deliberate: a section headed "Frequently Bought
 * Together" showing arbitrary catalogue filler is worse than no section.
 *
 * Expect tier 2 to carry this feature for a while. Measured on this store, only
 * ~888 of ~185,000 simple products have any co-purchase partner - about 0.5% - so
 * tier 1 rarely fires today and improves on its own as orders accumulate.
 *
 * The previous implementation picked the two newest products from the current
 * product's own deepest category, which meant suggestions were substitutes
 * (another shirt) rather than companions, and had no relationship to what anyone
 * had bought.
 */
class FbtSuggestionProvider
{
    private const XML_PATH_MAX_SUGGESTIONS = 'pdprevamp_fbt/general/max_suggestions';
    private const XML_PATH_MIN_COPURCHASE = 'pdprevamp_fbt/general/min_copurchase_orders';
    private const XML_PATH_EXCLUDED_SKUS = 'pdprevamp_fbt/general/excluded_skus';

    private const DEFAULT_MAX_SUGGESTIONS = 2;

    private ProductAffinity $productAffinity;
    private CollectionFactory $productCollectionFactory;
    private ResourceConnection $resourceConnection;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    /** Memoised; 0 means looked up and absent. */
    private ?int $positionAttributeId = null;

    public function __construct(
        ProductAffinity $productAffinity,
        CollectionFactory $productCollectionFactory,
        ResourceConnection $resourceConnection,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->productAffinity = $productAffinity;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * @return Product[] empty when there is nothing trustworthy to show
     */
    public function getSuggestions(Product $product): array
    {
        $limit = $this->getMaxSuggestions();
        if ($limit < 1) {
            return [];
        }

        $productId = (int) $product->getId();
        if ($productId < 1) {
            return [];
        }

        $excludedIds = $this->getExcludedProductIds();
        $excludedIds[] = $productId;

        $candidateIds = [];

        // pdp_fbt_force_manual lets a merchant who has curated a bundle keep it,
        // rather than being silently overridden once the product gains order
        // history. Note this skips tier 1 entirely - if their list is empty the
        // section disappears, which is what "always use my picks" has to mean.
        if (!$this->isForcedManual($product)) {
            $candidateIds = $this->productAffinity->getRelatedProductIds(
                $productId,
                // Over-fetch: some ids will fail the salable/simple/price checks
                // below, and re-querying per rejection would be worse.
                $limit * 4,
                $excludedIds,
                $this->getMinCoPurchaseOrders()
            );
        }

        $suggestions = $this->loadValidProducts($candidateIds, $excludedIds, $limit);

        // Top up from the manual list rather than showing one product - a
        // two-item bundle is still a bundle.
        if (count($suggestions) < $limit) {
            $alreadyChosen = array_map(
                static fn (Product $item): int => (int) $item->getId(),
                $suggestions
            );

            $manualIds = array_diff(
                $this->getManualProductIds($product),
                $alreadyChosen,
                $excludedIds
            );

            $suggestions = array_merge(
                $suggestions,
                $this->loadValidProducts(
                    $manualIds,
                    array_merge($excludedIds, $alreadyChosen),
                    $limit - count($suggestions)
                )
            );
        }

        return $suggestions;
    }

    /**
     * Loads the given ids, keeping only products the section can actually offer,
     * and preserving the order the ids arrived in.
     *
     * The type filter is not cosmetic: the section's one-click add posts only
     * product/qty/form_key, which cannot satisfy a configurable's required
     * super_attribute or a bundle's options, so those are rejected server-side
     * with "You need to choose options for your item" and the button silently
     * fails for that row.
     *
     * @param int[] $ids
     * @param int[] $excludedIds
     * @return Product[]
     */
    private function loadValidProducts(array $ids, array $excludedIds, int $limit): array
    {
        $ids = array_values(array_diff(array_unique(array_map('intval', $ids)), $excludedIds));

        if (!$ids || $limit < 1) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'price', 'special_price', 'image', 'small_image'])
            ->addAttributeToFilter('entity_id', ['in' => $ids])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
            ->addAttributeToFilter('type_id', ProductType::TYPE_SIMPLE);

        $byId = [];
        foreach ($collection as $candidate) {
            $byId[(int) $candidate->getId()] = $candidate;
        }

        $valid = [];
        // Iterate $ids, not the collection: the ranking is in the id order and a
        // collection does not preserve it.
        foreach ($ids as $id) {
            if (count($valid) >= $limit) {
                break;
            }

            $candidate = $byId[$id] ?? null;
            if ($candidate === null) {
                continue;
            }

            try {
                if (!$candidate->isSalable()) {
                    continue;
                }

                $finalPrice = (float) $candidate->getPriceInfo()
                    ->getPrice('final_price')->getAmount()->getValue();
                if ($finalPrice <= 0) {
                    continue;
                }
            } catch (\Throwable $exception) {
                continue;
            }

            $valid[] = $candidate;
        }

        return $valid;
    }

    /**
     * Manually picked products, in the order the admin arranged them.
     *
     * Read straight from catalog_product_link rather than through the product's
     * link collection: this only needs ids in position order, and loading the
     * link models would hydrate products twice.
     *
     * @return int[]
     */
    private function getManualProductIds(Product $product): array
    {
        $connection = $this->resourceConnection->getConnection();
        $linkTable = $this->resourceConnection->getTableName('catalog_product_link');
        $attrIntTable = $this->resourceConnection->getTableName('catalog_product_link_attribute_int');

        $select = $connection->select()
            ->from(['l' => $linkTable], ['linked_product_id'])
            ->where('l.product_id = ?', (int) $product->getId())
            ->where('l.link_type_id = ?', CreateManualFbtLinkType::LINK_TYPE_MANUAL_FBT);

        $positionAttributeId = $this->getPositionAttributeId();
        if ($positionAttributeId !== null) {
            $select->joinLeft(
                ['p' => $attrIntTable],
                $connection->quoteInto(
                    'p.link_id = l.link_id AND p.product_link_attribute_id = ?',
                    $positionAttributeId
                ),
                []
            )->order('p.value ASC');
        }

        $select->order('l.link_id ASC');

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Id of the position attribute belonging to the manual-FBT link type, or null
     * when the data patch has not run on this instance.
     */
    private function getPositionAttributeId(): ?int
    {
        if ($this->positionAttributeId !== null) {
            return $this->positionAttributeId ?: null;
        }

        $connection = $this->resourceConnection->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('catalog_product_link_attribute'),
                    ['product_link_attribute_id']
                )
                ->where('link_type_id = ?', CreateManualFbtLinkType::LINK_TYPE_MANUAL_FBT)
                ->where('product_link_attribute_code = ?', 'position')
        );

        // 0 is the "looked up, absent" marker so a missing row is not re-queried
        // on every call.
        $this->positionAttributeId = $id === false || $id === null ? 0 : (int) $id;

        return $this->positionAttributeId ?: null;
    }

    private function isForcedManual(Product $product): bool
    {
        return (bool) $product->getData(CreateFbtForceManualAttribute::ATTRIBUTE_CODE);
    }

    /**
     * Configured SKUs resolved to ids.
     *
     * Exact matches only - never a LIKE. Over 200 products in this catalogue
     * contain "free" or "decal" in their SKU and most are ordinary paid
     * merchandise; the paid "Be Lost" decals in particular sit one character away
     * from the free one. A pattern match would remove real sellable products.
     *
     * A SKU that resolves to nothing is logged rather than ignored silently: SKUs
     * are editable, so a rename turns this setting into a no-op and the excluded
     * product quietly reappears in suggestions.
     *
     * @return int[]
     */
    private function getExcludedProductIds(): array
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_SKUS,
            ScopeInterface::SCOPE_STORE
        ));

        if ($configured === '') {
            return [];
        }

        $skus = array_values(array_filter(array_map('trim', explode(',', $configured))));
        if (!$skus) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName('catalog_product_entity'),
                    ['entity_id', 'sku']
                )
                ->where('sku IN (?)', $skus)
        );

        $found = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['entity_id'];
            $found[] = (string) $row['sku'];
        }

        $missing = array_diff($skus, $found);
        if ($missing) {
            $this->logger->warning(
                '[PDPRevamp] FBT excluded_skus contains SKUs that match no product: '
                . implode(', ', $missing)
                . '. They are being ignored - check for a renamed product.'
            );
        }

        return $ids;
    }

    private function getMaxSuggestions(): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MAX_SUGGESTIONS,
            ScopeInterface::SCOPE_STORE
        );

        return $value > 0 ? $value : self::DEFAULT_MAX_SUGGESTIONS;
    }

    private function getMinCoPurchaseOrders(): ?int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_COPURCHASE,
            ScopeInterface::SCOPE_STORE
        );

        return $value > 0 ? $value : null;
    }
}
