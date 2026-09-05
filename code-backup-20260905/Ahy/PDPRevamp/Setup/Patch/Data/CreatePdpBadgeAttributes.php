<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates 4 icon+text badge attribute pairs for the PDP badges row
 * (e.g. "Only 1.1lbs Ultralight"). Each pair is independently optional -
 * a badge only renders once its text is filled in for a given product.
 */
class CreatePdpBadgeAttributes implements DataPatchInterface
{
    public const ICON_OPTIONS = [
        'weight' => 'Weight (scale)',
        'waterproof' => 'Waterproof (cloud)',
        'durability' => 'Durability (shield)',
        'compartments' => 'Compartments (grid)',
        'lightweight' => 'Lightweight (lightning bolt)',
        'eco' => 'Eco-friendly (globe)',
        'premium' => 'Premium (sparkles)',
        'warranty' => 'Warranty (badge check)',
        'quick_dry' => 'Quick-Drying (refresh)',
        'sun_protection' => 'UV / Sun Protection (sun)',
        'insulated' => 'Insulated / Warm (fire)',
        'odor_resistant' => 'Anti-Microbial / Odor Resistant (beaker)',
        'stretch' => 'Stretch / Flexible Fit (adjustments)',
        'made_in_usa' => 'Made in USA (flag)',
        'rugged_build' => 'Rugged Build (shield)',
        'water_resistant' => 'Water Resistant (cloud)',
        'windproof' => 'Windproof (shield)',
        'breathable' => 'Breathable (wifi)',
        'trail_tested' => 'Trail Tested (map)',
        'adventure_ready' => 'Adventure Ready (location pin)',
        'expedition_ready' => 'Expedition Ready (globe)',
        'built_to_last' => 'Built to Last (shield)',
        'heavy_duty' => 'Heavy Duty (shield)',
        'ergonomic' => 'Ergonomic Design (hand)',
        'adjustable_fit' => 'Adjustable Fit (adjustments)',
        'ripstop' => 'Ripstop Fabric (shield)',
        'reinforced_stitching' => 'Reinforced Stitching (link)',
        'rust_resistant' => 'Rust Resistant (shield)',
        'compact' => 'Compact (cube)',
        'packable' => 'Packable (archive)',
        'foldable' => 'Foldable (archive)',
        'easy_setup' => 'Easy Setup (cog)',
        'quick_setup' => 'Quick Setup (lightning bolt)',
        'rechargeable' => 'Rechargeable (chip)',
        'solar_powered' => 'Solar Powered (sun)',
        'long_battery_life' => 'Long Battery Life (clock)',
        'reflective_details' => 'Reflective Details (sparkles)',
        'fire_resistant' => 'Fire Resistant (fire)',
        'recycled_materials' => 'Recycled Materials (refresh)',
        'hiking' => 'Hiking (map)',
        'camping' => 'Camping (home)',
        'backpacking' => 'Backpacking (briefcase)',
        'bushcraft' => 'Bushcraft (scissors)',
        'survival' => 'Survival (shield exclamation)',
        'climbing' => 'Climbing (trending up)',
        'fishing' => 'Fishing (link)',
        'cycling' => 'Cycling (refresh)',
        'travel_ready' => 'Travel Ready (globe)',
        'edc' => 'Everyday Carry / EDC (briefcase)',
        'customer_favorite' => 'Customer Favorite (heart)',
        'best_seller' => 'Best Seller (trending up)',
        'top_rated' => 'Top Rated (star)',
        'award_winning' => 'Award Winning (badge check)',
        'lifetime_warranty' => 'Lifetime Warranty (badge check)',
        'food_safe' => 'Food Safe (cake)',
        'bpa_free' => 'BPA Free (beaker)',
        'professional_grade' => 'Professional Grade (briefcase)',
    ];

    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetupFactory $eavSetupFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var \Magento\Eav\Setup\EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        for ($i = 1; $i <= 4; $i++) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                "badge_{$i}_icon",
                [
                    'type' => 'varchar',
                    'label' => "PDP Badge {$i} Icon",
                    'input' => 'select',
                    'source' => \Ahy\PDPRevamp\Model\Product\Attribute\Source\BadgeIcon::class,
                    'required' => false,
                    'sort_order' => 100 + ($i * 2) - 1,
                    'global' => \Magento\Catalog\Model\ResourceModel\Eav\Attribute::SCOPE_GLOBAL,
                    'group' => 'Badges',
                    'visible' => true,
                    'user_defined' => true,
                    'is_used_in_grid' => false,
                    'is_visible_in_grid' => false,
                    'is_filterable_in_grid' => false,
                ]
            );

            $eavSetup->addAttribute(
                Product::ENTITY,
                "badge_{$i}_text",
                [
                    'type' => 'varchar',
                    'label' => "PDP Badge {$i} Text",
                    'input' => 'text',
                    'required' => false,
                    'sort_order' => 100 + ($i * 2),
                    'global' => \Magento\Catalog\Model\ResourceModel\Eav\Attribute::SCOPE_GLOBAL,
                    'group' => 'Badges',
                    'visible' => true,
                    'user_defined' => true,
                    'is_used_in_grid' => false,
                    'is_visible_in_grid' => false,
                    'is_filterable_in_grid' => false,
                ]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

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
