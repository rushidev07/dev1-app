<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the "PDP " prefix from the attribute-set group labels used by this
 * module's attributes (Stores > Attribute Sets > ... group names). These
 * groups get created independently per attribute set (whenever an attribute
 * is first assigned to a set), so the same group name can exist as several
 * separate rows across many attribute sets - this renames all of them in
 * one pass, keyed purely by the current group name string.
 */
class RenamePdpAttributeGroups implements DataPatchInterface
{
    public const RENAMES = [
        'PDP Revamp' => 'Revamp',
        'PDP Badges' => 'Badges',
        'PDP Trust Row' => 'Trust Row',
    ];

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        foreach (self::RENAMES as $oldName => $newName) {
            $connection->update(
                $connection->getTableName('eav_attribute_group'),
                ['attribute_group_name' => $newName],
                ['attribute_group_name = ?' => $oldName]
            );
        }

        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            CreatePdpBadgeAttributes::class,
            CreatePdpAsSeenInAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
