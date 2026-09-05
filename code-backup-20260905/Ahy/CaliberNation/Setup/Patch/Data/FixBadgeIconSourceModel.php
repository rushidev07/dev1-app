<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Replaces the missing Ahy\PDPRevamp\Model\Product\Attribute\Source\BadgeIcon
 * source model on badge_*_icon product attributes with Magento's built-in
 * Table source, so the product edit page no longer throws a ReflectionException.
 *
 * No dependency on Ahy_PDPRevamp is introduced — this patch operates directly
 * on the eav_attribute table.
 */
class FixBadgeIconSourceModel implements DataPatchInterface
{
    private const BROKEN_SOURCE = 'Ahy\PDPRevamp\Model\Product\Attribute\Source\BadgeIcon';
    private const VALID_SOURCE  = \Magento\Eav\Model\Entity\Attribute\Source\Table::class;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {}

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table      = $this->moduleDataSetup->getTable('eav_attribute');

        $connection->update(
            $table,
            ['source_model' => self::VALID_SOURCE],
            ['source_model = ?' => self::BROKEN_SOURCE]
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
