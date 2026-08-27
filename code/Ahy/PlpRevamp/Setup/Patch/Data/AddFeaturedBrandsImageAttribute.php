<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Ahy\PlpRevamp\Model\Category\Attribute\Backend\FeaturedBrands;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds `ahy_featured_brands` — a JSON list of brand cards ({name, link, image}) managed via a
 * dynamicRows field on the category form, superseding the comma-separated `ahy_featured_brand_names`.
 * The old attribute is retained so existing values still render (the blocks fall back to it).
 */
class AddFeaturedBrandsImageAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_featured_brands')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_brands', [
                'type'     => 'text',
                'label'    => 'Featured Brands',
                'input'    => 'text',
                'backend'  => FeaturedBrands::class,
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Brand cards (name + link + image) shown in the Featured Brands section and on the All Brands page.',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddFeaturedBrandsAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
