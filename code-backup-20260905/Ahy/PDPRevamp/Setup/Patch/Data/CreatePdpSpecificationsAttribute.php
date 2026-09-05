<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the pdp_specifications textarea attribute backing the PDP
 * "Specifications" tab (see Block\Product\View\Specifications and
 * product/view/specifications.phtml).
 *
 * Deliberately a plain textarea rather than a dynamic-rows admin grid: this
 * module's one existing dynamic-rows field (Key Features) needed a fair
 * amount of custom JS to get its unattached data actually submitted with
 * the product (see key-features-dynamic-rows.js's own account of that), and
 * a fixed convention (one "Label: Value" pair per line, parsed on render)
 * gets the same table on the storefront with zero custom admin JS.
 */
class CreatePdpSpecificationsAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'pdp_specifications';

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
            self::ATTRIBUTE_CODE,
            [
                'type' => 'text',
                'label' => 'Specifications (one "Label: Value" per line)',
                'input' => 'textarea',
                'required' => false,
                'sort_order' => 10,
                'global' => Attribute::SCOPE_GLOBAL,
                'group' => 'Specifications',
                'visible' => true,
                'user_defined' => true,
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
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
