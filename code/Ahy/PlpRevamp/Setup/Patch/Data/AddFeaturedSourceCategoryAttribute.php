<?php
declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddFeaturedSourceCategoryAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_featured_source_id')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_source_id', [
                'type'     => 'varchar',
                'label'    => 'Featured Products Source Category ID',
                'input'    => 'text',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Category ID whose products appear in Featured Products section. That category is hidden from subcategory cards.',
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
        return [
            \Ahy\ThemeCustomization\Setup\Patch\Data\AddFeaturedSourceCategoryAttribute::class,
        ];
    }
}
