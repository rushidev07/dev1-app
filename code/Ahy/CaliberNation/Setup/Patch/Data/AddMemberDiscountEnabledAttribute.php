<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds `caliber_member_discount_enabled` (boolean) to the product form's
 * "Caliber Nation Member Pricing" group. It's an explicit ON/OFF for the
 * product-level member discount, so a configured type/value can be PAUSED
 * (turned off) without losing the numbers, then restored.
 *
 * Default OFF (new products opt in explicitly). Existing products that already
 * carry a positive discount value are backfilled to ON so their discounts keep
 * applying after this patch.
 */
class AddMemberDiscountEnabledAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'caliber_member_discount_enabled';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
                'type'                    => 'int',
                'label'                   => 'Enable Member Discount',
                'input'                   => 'boolean',
                'source'                  => Boolean::class,
                'required'                => false,
                'user_defined'            => true,
                'default'                 => '0',
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'                   => 'Caliber Nation Member Pricing',
                'sort_order'              => 5,
                'visible'                 => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => false,
                'used_in_product_listing' => true,
                // Kept in step with UpdateMemberDiscountEnabledNote, which refreshes this
                // wording on installs where the attribute already exists.
                'note'                    => 'Turn ON for the product-level member discount for additional discount to apply. Turn OFF to pause it without losing the type/value.',
            ]);
        }

        $this->backfillExistingDiscounts($eavSetup);

        return $this;
    }

    /**
     * Products that already have a positive product-level discount value keep
     * working: set their new enabled flag to 1 (store scope 0, global attribute).
     */
    private function backfillExistingDiscounts(EavSetup $eavSetup): void
    {
        $enabledId = (int) $eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);
        $valueId   = (int) $eavSetup->getAttributeId(Product::ENTITY, 'caliber_member_discount_value');
        if (!$enabledId || !$valueId) {
            return;
        }

        $conn     = $this->moduleDataSetup->getConnection();
        $intTable = $this->moduleDataSetup->getTable('catalog_product_entity_int');
        $decTable = $this->moduleDataSetup->getTable('catalog_product_entity_decimal');

        $conn->query(
            "INSERT INTO {$intTable} (attribute_id, store_id, entity_id, value) "
            . "SELECT {$enabledId}, 0, d.entity_id, 1 "
            . "FROM {$decTable} d "
            . "WHERE d.attribute_id = {$valueId} AND d.store_id = 0 AND d.value > 0 "
            . "ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
    }

    public static function getDependencies(): array
    {
        return [AddMemberPricingProductAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
