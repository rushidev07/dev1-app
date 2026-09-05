<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Registers two new product link types (same mechanism as Magento's own
 * built-in Related/Up-Sell/Cross-Sell: catalog_product_link_type +
 * catalog_product_link_attribute) so admin gets a native product-picker
 * grid on every product's edit page for manually overriding what shows in
 * the "Customers Also Bought" and "Adventure Seekers Also Viewed" PDP
 * sections when Amasty's automatic data has nothing for that product yet.
 *
 * IDs 90/91 and product_link_attribute_id 90/91 are chosen well clear of
 * Magento's own core link types (1, 3, 4, 5) and their attribute rows
 * (1-5) - confirmed no collision against the live catalog_product_link_type
 * / catalog_product_link_attribute tables before picking these.
 */
class CreateManualCarouselLinkTypes implements DataPatchInterface
{
    public const LINK_TYPE_MANUAL_CAB = 90;
    public const LINK_TYPE_CODE_MANUAL_CAB = 'ahy_manual_cab';

    public const LINK_TYPE_MANUAL_ASAV = 91;
    public const LINK_TYPE_CODE_MANUAL_ASAV = 'ahy_manual_asav';

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        $linkTypeTable = $this->moduleDataSetup->getTable('catalog_product_link_type');
        $linkAttributeTable = $this->moduleDataSetup->getTable('catalog_product_link_attribute');

        $types = [
            self::LINK_TYPE_MANUAL_CAB => self::LINK_TYPE_CODE_MANUAL_CAB,
            self::LINK_TYPE_MANUAL_ASAV => self::LINK_TYPE_CODE_MANUAL_ASAV,
        ];

        foreach ($types as $linkTypeId => $code) {
            $connection->insertOnDuplicate(
                $linkTypeTable,
                ['link_type_id' => $linkTypeId, 'code' => $code],
                ['code']
            );

            $connection->insertOnDuplicate(
                $linkAttributeTable,
                [
                    'product_link_attribute_id' => $linkTypeId,
                    'link_type_id' => $linkTypeId,
                    'product_link_attribute_code' => 'position',
                    'data_type' => 'int',
                ],
                ['link_type_id', 'product_link_attribute_code', 'data_type']
            );
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
