<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the as_seen_in multiselect attribute backing the PDP trust
 * row's "As Seen In" logos (see product/view/trust-row.phtml). Options are
 * plain admin-managed attribute options (Stores > Attributes > Product >
 * as_seen_in > Manage Options) - no source model, so admins can add,
 * rename or remove publications without a code deploy. Seeded here with
 * the publications that were previously hardcoded in the template.
 */
class CreatePdpAsSeenInAttribute implements DataPatchInterface
{
    public const DEFAULT_OPTIONS = [
        'Recoil Magazine',
        'Travel Daily News',
        'SGB Media',
        'InnovationMap',
        'Disrupt Magazine',
        'CultureMap',
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

        $eavSetup->addAttribute(
            Product::ENTITY,
            'as_seen_in',
            [
                'type' => 'varchar',
                'label' => 'As Seen In',
                'input' => 'multiselect',
                'backend' => ArrayBackend::class,
                'required' => false,
                'sort_order' => 10,
                'global' => Attribute::SCOPE_GLOBAL,
                'group' => 'As Seen In',
                'visible' => true,
                'user_defined' => true,
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'option' => [
                    'values' => self::DEFAULT_OPTIONS,
                ],
            ]
        );

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
