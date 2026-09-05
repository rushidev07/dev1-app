<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * Batch/cron/full sync only — NOT used by the real-time save observer.
 *
 * A configurable product's children (size/color variants etc.) each carry
 * their OWN independent `status` attribute in Magento -- disabling the
 * parent listing does NOT automatically disable its children. Without this
 * check, a child SKU that's individually still Enabled would sync to the
 * search platform on its own, even though its parent (the only thing that
 * actually gets shown/sold as this product) is Disabled.
 *
 * Uses Magento's real parent-child relationship (catalog_product_super_link)
 * rather than any SKU-naming heuristic, so there's no risk of the false
 * positives that affect DuplicateSkuResolver's pattern-matching approach.
 */
class DisabledParentResolver
{
    // catalog_product entity_type_id — stable/hardcoded across stock Magento 2
    // installs, same convention CategoryFetcher uses for catalog_category (3).
    private const CATALOG_PRODUCT_ENTITY_TYPE_ID = 4;

    public function __construct(
        private readonly CollectionFactory  $collectionFactory,
        private readonly ResourceConnection $resourceConnection,
    ) {}

    /**
     * Entity IDs of configurable-child products whose parent is Disabled,
     * for the given store (0 = default scope only).
     */
    public function getOrphanedChildIds(int $storeId = 0): array
    {
        $disabledParents = $this->collectionFactory->create();
        $disabledParents->addAttributeToFilter('type_id', 'configurable');
        $disabledParents->addAttributeToFilter('status', Status::STATUS_DISABLED);
        if ($storeId > 0) {
            $disabledParents->addStoreFilter($storeId);
        }

        $parentIds = array_map('intval', $disabledParents->getAllIds());
        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $childIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_super_link'), ['product_id'])
                ->where('parent_id IN (?)', $parentIds)
        );

        return array_map('intval', $childIds);
    }

    /**
     * Entity IDs of configurable-parent products that have at least one child
     * (simple product variant) priced above $maxPrice.
     *
     * Checking only the configurable's own `price` attribute isn't enough:
     * Magento often leaves a configurable's own price null or set to the
     * lowest child's price, which can mask a single badly-priced variant
     * (e.g. placeholder pricing like 99999 on just one color/size option).
     * This walks the real parent-child relationship (catalog_product_super_link)
     * and checks every child's own price directly, same relationship used by
     * getOrphanedChildIds() above.
     */
    public function getConfigurableParentIdsWithOverpricedChild(float $maxPrice, int $storeId = 0): array
    {
        $attributeId = $this->resolvePriceAttributeId();
        if (!$attributeId) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $linkTable  = $this->resourceConnection->getTableName('catalog_product_super_link');
        $priceTable = $this->resourceConnection->getTableName('catalog_product_entity_decimal');

        // Check both the default-scope price row (store_id=0) and any
        // store-specific override — a placeholder price showing up in either
        // scope is enough to disqualify the child.
        $scopeStoreIds = array_unique([0, $storeId]);

        $select = $connection->select()
            ->from(['l' => $linkTable], ['parent_id'])
            ->joinInner(
                ['d' => $priceTable],
                'd.entity_id = l.product_id AND d.attribute_id = ' . $attributeId,
                []
            )
            ->where('d.store_id IN (?)', $scopeStoreIds)
            ->where('d.value > ?', $maxPrice)
            ->distinct(true);

        $parentIds = $connection->fetchCol($select);

        return array_map('intval', $parentIds);
    }

    /**
     * Configurable parents whose OWN price attribute (not any child's) exceeds
     * $maxPrice. A configurable's own price is often null/derived from its
     * cheapest child, but when it IS set directly it can carry the same kind
     * of placeholder pricing a simple product can — this must exclude the
     * family too, not just rely on the child-side check above.
     */
    private function getConfigurableParentIdsWithOwnOverpricedPrice(float $maxPrice, int $storeId = 0): array
    {
        $attributeId = $this->resolvePriceAttributeId();
        if (!$attributeId) {
            return [];
        }

        $connection    = $this->resourceConnection->getConnection();
        $priceTable    = $this->resourceConnection->getTableName('catalog_product_entity_decimal');
        $entityTable   = $this->resourceConnection->getTableName('catalog_product_entity');
        $scopeStoreIds = array_unique([0, $storeId]);

        $select = $connection->select()
            ->from(['cpe' => $entityTable], ['entity_id'])
            ->joinInner(
                ['d' => $priceTable],
                'd.entity_id = cpe.entity_id AND d.attribute_id = ' . $attributeId,
                []
            )
            ->where('cpe.type_id = ?', 'configurable')
            ->where('d.store_id IN (?)', $scopeStoreIds)
            ->where('d.value > ?', $maxPrice)
            ->distinct(true);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * All product IDs — the configurable parent AND every one of its children —
     * that must be excluded from (or swept out of) sync because the family is
     * overpriced: either the parent's own price exceeds $maxPrice, or at least
     * one child does. The whole family is returned together, not just the
     * offending member: the parent's normalize() embeds every child inside its
     * own `variants` array, so suppressing only the standalone child doc would
     * still ship the bad price bundled inside the parent's document, and
     * leaving healthy siblings synced under a pulled parent would orphan them.
     */
    public function getOverpricedConfigurableFamilyIds(float $maxPrice, int $storeId = 0): array
    {
        $parentIds = array_unique(array_merge(
            $this->getConfigurableParentIdsWithOverpricedChild($maxPrice, $storeId),
            $this->getConfigurableParentIdsWithOwnOverpricedPrice($maxPrice, $storeId)
        ));

        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $childIds   = $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_super_link'), ['product_id'])
                ->where('parent_id IN (?)', $parentIds)
        );

        return array_values(array_unique(array_map('intval', array_merge($parentIds, $childIds))));
    }

    /**
     * Configurable-parent entity IDs where NOT ONE child variant is in stock.
     *
     * A configurable parent's OWN cataloginventory_stock_item row is virtually
     * always is_in_stock=1 in Magento regardless of its children — configurables
     * don't manage stock themselves, only their simple-product children do. That
     * means neither buildCollection()'s eligibility filter nor
     * buildDisabledOrOosCollection()'s sweep filter (both of which check a
     * product's OWN stock item) can ever see a configurable parent as
     * out-of-stock, even when every child genuinely is. This walks the real
     * parent-child relationship (catalog_product_super_link) and checks each
     * child's own stock item directly — the same relationship/approach
     * getOrphanedChildIds() and the overpriced-family checks above use.
     *
     * Children themselves are NOT included in the return value: each
     * individually-OOS child already has is_in_stock=0 on its own row, so both
     * buildCollection() and buildDisabledOrOosCollection() already handle them
     * correctly without help. Only the parent needs this separate check.
     */
    public function getConfigurableParentIdsWithNoInStockChild(int $storeId = 0): array
    {
        $connection = $this->resourceConnection->getConnection();
        $linkTable  = $this->resourceConnection->getTableName('catalog_product_super_link');
        $stockTable = $this->resourceConnection->getTableName('cataloginventory_stock_item');

        $allParentIds = $connection->fetchCol(
            $connection->select()->from($linkTable, ['parent_id'])->distinct(true)
        );
        if (empty($allParentIds)) {
            return [];
        }

        $parentIdsWithInStockChild = $connection->fetchCol(
            $connection->select()
                ->from(['l' => $linkTable], ['parent_id'])
                ->joinInner(
                    ['si' => $stockTable],
                    'si.product_id = l.product_id AND si.stock_id = 1',
                    []
                )
                ->where('si.is_in_stock = ?', 1)
                ->distinct(true)
        );

        return array_values(array_map('intval', array_diff($allParentIds, $parentIdsWithInStockChild)));
    }

    private function resolvePriceAttributeId(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'price')
                ->where('entity_type_id = ?', self::CATALOG_PRODUCT_ENTITY_TYPE_ID)
        );
    }
}
