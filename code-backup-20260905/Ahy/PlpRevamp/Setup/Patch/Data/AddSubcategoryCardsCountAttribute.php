<?php
declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddSubcategoryCardsCountAttribute implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_subcategory_cards_count')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_subcategory_cards_count', [
                'type'     => 'varchar',
                'label'    => 'Subcategory Cards Count',
                'input'    => 'text',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Number of subcategory cards to show. Leave empty or 0 to show all.',
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
