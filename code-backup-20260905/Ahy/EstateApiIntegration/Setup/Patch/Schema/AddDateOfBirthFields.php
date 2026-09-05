<?php

declare(strict_types=1);

namespace Ahy\EstateApiIntegration\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class AddDateOfBirthFields implements SchemaPatchInterface
{
    private const COLUMNS = [
        'dob_year' => 'Purchaser Date Of Birth - Year',
        'dob_month' => 'Purchaser Date Of Birth - Month',
        'dob_day' => 'Purchaser Date Of Birth - Day',
    ];

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup
    ) {}

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();

        foreach (['quote', 'sales_order'] as $table) {
            $tableName = $this->moduleDataSetup->getTable($table);

            foreach (self::COLUMNS as $column => $comment) {
                if (!$connection->tableColumnExists($tableName, $column)) {
                    $connection->addColumn(
                        $tableName,
                        $column,
                        [
                            'type'     => Table::TYPE_SMALLINT,
                            'nullable' => true,
                            'comment'  => $comment,
                        ]
                    );
                }
            }
        }

        $this->moduleDataSetup->endSetup();
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
