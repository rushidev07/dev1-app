<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Model\Category;

use Ahy\PlpRevamp\Setup\Patch\Data\AddFeaturedContainerFlagAttribute as ContainerFlag;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;

/**
 * Creates the "Featured Products" child category for a category and points the
 * parent's ahy_featured_source_id at it.
 *
 * Shared by the console backfill (ahy:plp:featured-categories:create) and the
 * category save observer so both behave identically — one definition of "what a
 * Featured Products category is", not two that drift.
 */
class FeaturedCategoryManager
{
    public const CATEGORY_NAME    = 'Featured Products';
    public const SOURCE_ATTRIBUTE = 'ahy_featured_source_id';

    /** Root and store-root categories have no Featured Products section to fill. */
    private const MIN_LEVEL = 2;

    public function __construct(
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryResource $categoryResource
    ) {}

    /**
     * Id of an existing "Featured Products" child under this parent, if there is one.
     *
     * An interrupted run can leave a container created but unassigned — the child
     * exists, the parent's source id does not. Without this the next run tries to
     * create a second one and dies on "URL key for specified store already exists",
     * because the url key is derived from the parent and is therefore identical.
     */
    public function findExistingChild(Category $parent): ?int
    {
        $parentId = (int) $parent->getId();
        if ($parentId <= 0) {
            return null;
        }

        $connection = $this->categoryResource->getConnection();

        $childIds = $connection->fetchCol(
            $connection->select()
                ->from($this->categoryResource->getEntityTable(), ['entity_id'])
                ->where('parent_id = ?', $parentId)
        );
        if (!$childIds) {
            return null;
        }

        $nameAttribute = $this->categoryResource->getAttribute('name');

        $id = (int) $connection->fetchOne(
            $connection->select()
                ->from($nameAttribute->getBackend()->getTable(), ['entity_id'])
                ->where('attribute_id = ?', (int) $nameAttribute->getAttributeId())
                ->where('store_id = ?', 0)
                ->where('value = ?', self::CATEGORY_NAME)
                ->where('entity_id IN (?)', $childIds)
                ->limit(1)
        );

        return $id > 0 ? $id : null;
    }

    /**
     * Whether this category should receive a Featured Products child.
     *
     * @param string|null $reason Set to a human-readable explanation when false.
     */
    public function isEligible(Category $category, ?string &$reason = null): bool
    {
        if ((int) $category->getLevel() < self::MIN_LEVEL) {
            $reason = 'root or store-root category';
            return false;
        }
        if ((int) $category->getData(ContainerFlag::ATTRIBUTE_CODE) === 1) {
            $reason = 'is itself a Featured Products container';
            return false;
        }
        if (trim((string) $category->getName()) === self::CATEGORY_NAME) {
            // Belt and braces: catches containers created before the flag existed.
            $reason = 'already named "' . self::CATEGORY_NAME . '"';
            return false;
        }
        if ((string) $category->getData(self::SOURCE_ATTRIBUTE) !== '') {
            $reason = 'already has a source category (' . $category->getData(self::SOURCE_ATTRIBUTE) . ')';
            return false;
        }
        return true;
    }

    /**
     * Create the Featured Products child and assign its id to the parent.
     *
     * @param int[] $storeIds Scopes to write ahy_featured_source_id at. 0 = default
     *                        scope, which every store view inherits.
     * @return int The new category id.
     */
    public function create(Category $parent, array $storeIds = [0]): int
    {
        // Adopt an orphan from a previous interrupted run rather than duplicating it.
        $existingId = $this->findExistingChild($parent);
        if ($existingId !== null) {
            $this->assign($parent, $existingId, $storeIds);
            return $existingId;
        }

        $child = $this->categoryFactory->create();
        $child->setName(self::CATEGORY_NAME)
            ->setParentId((int) $parent->getId())
            // Path comes from the parent we already hold. CategoryRepository::save()
            // would re-load the parent just to read this, then re-load the saved child
            // on the way out — two full category loads per row, which is what made the
            // backfill crawl. The resource model derives level/position/parent_id from
            // path in _beforeSave(), so this is the same result without the round trips.
            ->setPath((string) $parent->getPath())
            ->setIsActive(true)
            ->setIncludeInMenu(false)
            ->setIsAnchor(false)
            ->setDisplayMode(Category::DM_PRODUCT)
            ->setData(ContainerFlag::ATTRIBUTE_CODE, 1)
            // Every sibling set would otherwise generate the same "featured-products"
            // url key. Prefixing with the parent's key keeps rewrites unique and the
            // URL readable, while the visible name stays "Featured Products".
            ->setUrlKey($this->buildUrlKey($parent))
            ->setAttributeSetId($child->getDefaultAttributeSetId())
            // Brand-new category — there is no rewrite history worth keeping.
            ->setData('save_rewrites_history', false)
            ->setStoreId(0);

        // Resource save, not repository save. Still dispatches catalog_category_save_after,
        // so URL rewrites are generated and the "See More" link keeps working.
        $this->categoryResource->save($child);
        $childId = (int) $child->getId();

        $this->assign($parent, $childId, $storeIds);

        return $childId;
    }

    /**
     * Write ahy_featured_source_id on the parent at each requested scope.
     *
     * Uses saveAttribute() rather than a full repository save: it touches one EAV
     * row and does NOT re-dispatch catalog_category_save_after, so the observer
     * cannot re-enter through its own write.
     *
     * @param int[] $storeIds
     */
    public function assign(Category $parent, int $childId, array $storeIds = [0]): void
    {
        foreach (array_unique(array_map('intval', $storeIds)) as $storeId) {
            $parent->setStoreId($storeId);
            $parent->setData(self::SOURCE_ATTRIBUTE, (string) $childId);
            $this->categoryResource->saveAttribute($parent, self::SOURCE_ATTRIBUTE);
        }
        $parent->setStoreId(0);
    }

    private function buildUrlKey(Category $parent): string
    {
        $parentKey = trim((string) $parent->getUrlKey());
        if ($parentKey === '') {
            $parentKey = 'category-' . (int) $parent->getId();
        }
        return $parentKey . '-featured-products';
    }
}
