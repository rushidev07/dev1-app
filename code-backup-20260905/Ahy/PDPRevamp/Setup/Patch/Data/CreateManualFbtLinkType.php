<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers a product link type for manually chosen "Frequently Bought Together"
 * products, giving admin a native product-picker grid on the product edit page.
 *
 * Same mechanism as CreateManualCarouselLinkTypes (90/91) - and deliberately a
 * separate patch rather than an extra entry in that one, because that patch has
 * already run everywhere. Adding to it would not re-apply.
 *
 * Id 92 is the next free slot: core uses 1, 3, 4, 5 and this module already holds
 * 90 and 91. Verified against catalog_product_link_type and
 * catalog_product_link_attribute before choosing it.
 *
 * These picks are the FBT section's tier 2 - used when the co-purchase query
 * (tier 1) finds nothing, or when a product has pdp_fbt_force_manual set.
 */
class CreateManualFbtLinkType implements DataPatchInterface
{
    public const LINK_TYPE_MANUAL_FBT = 92;
    public const LINK_TYPE_CODE_MANUAL_FBT = 'ahy_manual_fbt';

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('catalog_product_link_type'),
            [
                'link_type_id' => self::LINK_TYPE_MANUAL_FBT,
                'code' => self::LINK_TYPE_CODE_MANUAL_FBT,
            ],
            ['code']
        );

        // The position attribute is what makes the picker grid orderable, which is
        // how "first N by position" is resolved when an admin picks more than the
        // section shows.
        $connection->insertOnDuplicate(
            $this->moduleDataSetup->getTable('catalog_product_link_attribute'),
            [
                'product_link_attribute_id' => self::LINK_TYPE_MANUAL_FBT,
                'link_type_id' => self::LINK_TYPE_MANUAL_FBT,
                'product_link_attribute_code' => 'position',
                'data_type' => 'int',
            ],
            ['link_type_id', 'product_link_attribute_code', 'data_type']
        );

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
