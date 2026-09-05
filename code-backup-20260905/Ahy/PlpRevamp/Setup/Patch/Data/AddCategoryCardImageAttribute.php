<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Category\Attribute\Backend\Image;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCategoryCardImageAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_card_image')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_card_image', [
                'type'     => 'varchar',
                'label'    => 'Category Card Image',
                'input'    => 'image',
                'backend'  => Image::class,
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Custom image shown on the subcategory card. Falls back to default category image if not set.',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            AddSubcategoryLayoutAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
