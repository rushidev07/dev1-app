<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the "pdp_" prefix from attribute codes that were originally created
 * with it. On environments where these attributes already exist under the
 * old code, this renames them in place (attribute_id stays the same, so
 * existing product data is unaffected). On a fresh install, the creation
 * patches already use the new codes directly, so each rename here is a
 * no-op (old code simply won't be found).
 *
 * pdp_feature_icons -> feature_icons was created outside of any patch in
 * this module (no code ever referenced it) but is renamed here too for
 * consistency, since it already exists on this project's environments.
 */
class RenamePdpAttributeCodes implements DataPatchInterface
{
    public const RENAMES = [
        'pdp_as_seen_in' => 'as_seen_in',
        'pdp_badge_1_icon' => 'badge_1_icon',
        'pdp_badge_1_text' => 'badge_1_text',
        'pdp_badge_2_icon' => 'badge_2_icon',
        'pdp_badge_2_text' => 'badge_2_text',
        'pdp_badge_3_icon' => 'badge_3_icon',
        'pdp_badge_3_text' => 'badge_3_text',
        'pdp_badge_4_icon' => 'badge_4_icon',
        'pdp_badge_4_text' => 'badge_4_text',
        'pdp_feature_icons' => 'feature_icons',
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
        $connection = $this->moduleDataSetup->getConnection();

        foreach (self::RENAMES as $oldCode => $newCode) {
            $attributeId = $eavSetup->getAttributeId(Product::ENTITY, $oldCode);
            if (!$attributeId) {
                continue;
            }
            $connection->update(
                $connection->getTableName('eav_attribute'),
                ['attribute_code' => $newCode],
                ['attribute_id = ?' => $attributeId]
            );
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            CreatePdpAsSeenInAttribute::class,
            FixPdpAsSeenInAttributeBackend::class,
            CreatePdpBadgeAttributes::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
