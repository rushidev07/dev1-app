<?php
declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddFeaturedBrandsAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_featured_brands_title')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_brands_title', [
                'type'     => 'varchar',
                'label'    => 'Featured Brands Section Title',
                'input'    => 'text',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Heading shown above the featured brands grid. Leave empty to hide the section.',
            ]);
        }

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_featured_brand_names')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_brand_names', [
                'type'     => 'varchar',
                'label'    => 'Featured Brand Names',
                'input'    => 'text',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Comma-separated brand names matching manufacturer attribute options. E.g: Patagonia, The North Face, Columbia',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddSubcategoryLayoutAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
