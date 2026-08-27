<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Ahy\PlpRevamp\Model\Category\Attribute\Backend\FeaturedBrands;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Guarantees `ahy_featured_brands` exists AND is assigned to the category's default attribute set /
 * "Display Settings" group. A category attribute that is not in the set is silently dropped by
 * EAV on save (AbstractEntity::_collectSaveData), so this self-heals installs where the earlier
 * patch was recorded but the attribute never made it into the set.
 */
class EnsureFeaturedBrandsAttribute implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'ahy_featured_brands';
    private const GROUP = 'Display Settings';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Category::ENTITY, self::ATTRIBUTE_CODE, [
                'type'     => 'text',
                'label'    => 'Featured Brands',
                'input'    => 'text',
                'backend'  => FeaturedBrands::class,
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => self::GROUP,
                'visible'  => true,
                'default'  => '',
                'note'     => 'Brand cards (name + link + image) shown in the Featured Brands section and on the All Brands page.',
            ]);
        }

        // Explicitly (re)assign to the default set + group in case the attribute exists but was
        // never placed in the set — otherwise the category form silently discards it on save.
        $entityTypeId = $eavSetup->getEntityTypeId(Category::ENTITY);
        $setId        = $eavSetup->getDefaultAttributeSetId($entityTypeId);
        $eavSetup->addAttributeToGroup($entityTypeId, $setId, self::GROUP, self::ATTRIBUTE_CODE);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddFeaturedBrandsImageAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
