<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Hides `is_caliber_nation_membership` from the product edit form (is_visible = 0).
 *
 * The flag is system-managed — it's set on the membership product by
 * CreateMembershipProduct and only ever read by code. Showing an editable boolean
 * called "Is Caliber Nation Membership" on every product invited the wrong
 * assumption that it must be enabled to give a product member pricing (it does not;
 * member discounts come from the pricing engine). Hiding it removes that confusion
 * while leaving the attribute fully usable in code and the product grid.
 */
class HideMembershipFlagFromForm implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $code = AddMembershipProductAttribute::ATTRIBUTE_CODE;
        if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
            // Remove it from the product edit form. Left usable by code + grid.
            $eavSetup->updateAttribute(Product::ENTITY, $code, 'is_visible', 0);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddMembershipProductAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
