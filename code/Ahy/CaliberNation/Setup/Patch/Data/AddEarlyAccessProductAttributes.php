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
 * Adds three product attributes to the "Caliber Nation Early Access" group
 * on the product edit page:
 *
 *  - caliber_early_access          (Yes/No) — flag this product for early access
 *  - caliber_ea_member_start_at    (varchar) — per-product member start override
 *  - caliber_ea_public_start_at    (varchar) — per-product public start override
 *
 * Date overrides are plain text (YYYY-MM-DD HH:MM:SS). When blank the global
 * admin config dates apply. When set they take priority over the global config
 * and any seller-level override.
 */
class AddEarlyAccessProductAttributes implements DataPatchInterface
{
    private const GROUP = 'Caliber Nation Early Access';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // ── 1. Early Access flag ──────────────────────────────────────────────
        if (!$eavSetup->getAttributeId(Product::ENTITY, 'caliber_early_access')) {
            $eavSetup->addAttribute(Product::ENTITY, 'caliber_early_access', [
                'type'                    => 'int',
                'label'                   => 'Caliber Early Access',
                'input'                   => 'boolean',
                'source'                  => Boolean::class,
                'required'                => false,
                'user_defined'            => true,
                'default'                 => '0',
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'                   => self::GROUP,
                'sort_order'              => 10,
                'visible'                 => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => false,
                'used_in_product_listing' => true,
                'note'                    => 'Enable to show the early-bird badge on this product for active members. Uses global early-access dates unless overrides are set below.',
            ]);
        }

        // ── 2. Per-product member start date override ─────────────────────────
        if (!$eavSetup->getAttributeId(Product::ENTITY, 'caliber_ea_member_start_at')) {
            $eavSetup->addAttribute(Product::ENTITY, 'caliber_ea_member_start_at', [
                'type'                    => 'varchar',
                'label'                   => 'Early Access Member Start (override)',
                'input'                   => 'text',
                'required'                => false,
                'user_defined'            => true,
                'default'                 => '',
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'                   => self::GROUP,
                'sort_order'              => 20,
                'visible'                 => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => false,
                'used_in_product_listing' => false,
                'note'                    => 'Optional. Format: YYYY-MM-DD HH:MM:SS (e.g. 2026-12-20 00:00:00). Overrides the global early-access member start date for this product only. Leave blank to use the global config date.',
            ]);
        }

        // ── 3. Per-product public start date override ─────────────────────────
        if (!$eavSetup->getAttributeId(Product::ENTITY, 'caliber_ea_public_start_at')) {
            $eavSetup->addAttribute(Product::ENTITY, 'caliber_ea_public_start_at', [
                'type'                    => 'varchar',
                'label'                   => 'Early Access Public Start (override)',
                'input'                   => 'text',
                'required'                => false,
                'user_defined'            => true,
                'default'                 => '',
                'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'                   => self::GROUP,
                'sort_order'              => 30,
                'visible'                 => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => false,
                'used_in_product_listing' => false,
                'note'                    => 'Optional. Format: YYYY-MM-DD HH:MM:SS. Overrides the global public start date for this product only. Leave blank to use the global config date.',
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
