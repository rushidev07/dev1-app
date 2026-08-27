<?php
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddSubcategoryLayoutAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Toggle: enable subcategory card layout for this category
        $eavSetup->addAttribute(Category::ENTITY, 'ahy_use_subcategory_layout', [
            'type'     => 'int',
            'label'    => 'Use Subcategory Card Layout',
            'input'    => 'boolean',
            'source'   => Boolean::class,
            'required' => false,
            'global'   => ScopedAttributeInterface::SCOPE_STORE,
            'group'    => 'Display Settings',
            'visible'  => true,
            'default'  => '0',
            'note'     => 'Enable to show subcategory cards + featured products instead of product grid.',
        ]);

        // Number of featured products to show (default 8)
        $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_products_count', [
            'type'     => 'varchar',
            'label'    => 'Featured Products Count',
            'input'    => 'text',
            'required' => false,
            'global'   => ScopedAttributeInterface::SCOPE_STORE,
            'group'    => 'Display Settings',
            'visible'  => true,
            'default'  => '8',
            'note'     => 'Number of featured products to show below subcategory cards.',
        ]);

        // Featured section heading
        $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_title', [
            'type'     => 'varchar',
            'label'    => 'Featured Products Section Title',
            'input'    => 'text',
            'required' => false,
            'global'   => ScopedAttributeInterface::SCOPE_STORE,
            'group'    => 'Display Settings',
            'visible'  => true,
            'default'  => 'Featured Products',
            'note'     => 'Heading text for the featured products section.',
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
