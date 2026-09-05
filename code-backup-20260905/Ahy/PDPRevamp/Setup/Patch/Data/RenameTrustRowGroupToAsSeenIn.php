<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Renames the "Trust Row" attribute-set group (see RenamePdpAttributeGroups)
 * to "As Seen In", across every attribute set it exists in.
 */
class RenameTrustRowGroupToAsSeenIn implements DataPatchInterface
{
    public function __construct(private ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $connection->update(
            $connection->getTableName('eav_attribute_group'),
            ['attribute_group_name' => 'As Seen In'],
            ['attribute_group_name = ?' => 'Trust Row']
        );

        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [RenamePdpAttributeGroups::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
