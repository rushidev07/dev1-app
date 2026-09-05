<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Ahy\CaliberNation\Model\Product\Attribute\Source\MemberDiscountType;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the product-level Caliber Nation member discount fields to the product
 * edit form (group "Caliber Nation Member Pricing"), so the product-scope
 * discount is configured on the product itself — no separate rule/target-id.
 *
 *  - caliber_member_discount_type  (percent | fixed)
 *  - caliber_member_discount_value (amount off)
 */
class AddMemberPricingProductAttributes implements DataPatchInterface
{
    private const GROUP = 'Caliber Nation Member Pricing';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(Product::ENTITY, 'caliber_member_discount_type', [
            'type'                    => 'varchar',
            'label'                   => 'Member Discount Type',
            'input'                   => 'select',
            'source'                  => MemberDiscountType::class,
            'required'                => false,
            'user_defined'            => true,
            'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
            'group'                   => self::GROUP,
            'sort_order'              => 10,
            'default'                 => '',
            'visible'                 => true,
            'used_in_product_listing' => true,
            'note'                    => 'Product-level member discount. Stacks additively on top of membership base + seller + category. Leave as None for no product discount.',
        ]);

        $eavSetup->addAttribute(Product::ENTITY, 'caliber_member_discount_value', [
            'type'                    => 'decimal',
            'label'                   => 'Member Discount Value',
            'input'                   => 'text',
            'required'                => false,
            'user_defined'            => true,
            'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
            'group'                   => self::GROUP,
            'sort_order'              => 20,
            'default'                 => '0',
            'visible'                 => true,
            'used_in_product_listing' => true,
            'frontend_class'          => 'validate-number validate-zero-or-greater',
            'note'                    => 'Percent → % off; Fixed → flat amount off. 0 = none.',
        ]);

        $this->moduleDataSetup->getConnection()->endSetup();
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
