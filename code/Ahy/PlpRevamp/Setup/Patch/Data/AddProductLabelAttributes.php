<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddProductLabelAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Text label — admin types anything: "Best Seller", "Free Shipping", etc.
        if (!$eavSetup->getAttribute(Product::ENTITY, 'ahy_product_label')) {
            $eavSetup->addAttribute(Product::ENTITY, 'ahy_product_label', [
                'type'                    => 'varchar',
                'label'                   => 'Product Label',
                'input'                   => 'text',
                'required'                => false,
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'used_in_product_listing' => true,
                'visible_on_front'        => false,
                'group'                   => 'General',
                'visible'                 => true,
                // EavSetup defaults this to 0, which makes Magento treat the attribute
                // as a system attribute and disable its Manage Options grid once a
                // source model is assigned. See MakeProductLabelAttributesUserDefined.
                'user_defined'            => true,
                'default'                 => '',
                'note'                    => 'Badge text shown on product card (e.g. Best Seller, Free Shipping). Leave empty for no badge.',
            ]);
        }

        // Color select — maps to a colored background on the badge
        if (!$eavSetup->getAttribute(Product::ENTITY, 'ahy_product_label_color')) {
            $eavSetup->addAttribute(Product::ENTITY, 'ahy_product_label_color', [
                'type'                    => 'varchar',
                'label'                   => 'Product Label Color',
                'input'                   => 'select',
                'required'                => false,
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'used_in_product_listing' => true,
                'visible_on_front'        => false,
                'group'                   => 'General',
                'visible'                 => true,
                // EavSetup defaults this to 0, which makes Magento treat the attribute
                // as a system attribute and disable its Manage Options grid once a
                // source model is assigned. See MakeProductLabelAttributesUserDefined.
                'user_defined'            => true,
                'default'                 => 'green',
                'option'                  => [
                    'values' => [
                        0 => 'Green',
                        1 => 'Orange',
                        2 => 'Blue',
                        3 => 'Red',
                        4 => 'Dark',
                        5 => 'Teal',
                    ],
                ],
                'note' => 'Background color of the product label badge.',
            ]);
        }

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
