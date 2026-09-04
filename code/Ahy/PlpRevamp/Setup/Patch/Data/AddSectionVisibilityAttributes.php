<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Per-section switches for the subcategory layout.
 *
 * `ahy_use_subcategory_layout` stays the master switch (it also drives the layout
 * handle and the 1-column forcing). These three decide which sections it renders,
 * so an admin can show subcategory cards without featured products, or brands on
 * their own, without deleting the configuration for the sections they hide.
 *
 * Default '1'. Note the readers in SubcategoryCards treat NULL as enabled too:
 * existing categories have no row for these attributes, and a backfill across
 * ~1,700 categories is exactly the kind of one-shot migration that is easy to get
 * wrong. Absent therefore means "on", matching how the page behaved before.
 */
class AddSectionVisibilityAttributes implements DataPatchInterface
{
    /** @var array<string,string> attribute code => admin label */
    private const ATTRIBUTES = [
        'ahy_show_subcategory_cards' => 'Enable Subcategory Cards',
        'ahy_show_featured_products' => 'Enable Featured Products',
        'ahy_show_featured_brands'   => 'Enable Featured Brands',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTES as $code => $label) {
            if ($eavSetup->getAttribute(Category::ENTITY, $code)) {
                continue;
            }

            $eavSetup->addAttribute(Category::ENTITY, $code, [
                'type'     => 'int',
                'label'    => $label,
                'input'    => 'boolean',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '1',
                'note'     => 'Only applies while Enable Subcategory Layout is on.',
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
