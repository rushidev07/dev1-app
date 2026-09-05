<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Ahy\PDPRevamp\Model\Product\Attribute\Backend\KeyFeatures;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the key_features attribute backing the PDP "Key Features" tile
 * grid (see product/view/key-features.phtml). Stores unlimited
 * title+description rows as a single JSON string via
 * Model/Product/Attribute/Backend/KeyFeatures.php, edited on the product
 * edit page through a dynamicRows override of this attribute's field (see
 * Ui/DataProvider/Product/Form/Modifier/KeyFeatures.php).
 */
class CreateKeyFeaturesAttribute implements DataPatchInterface
{
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
            'key_features',
            [
                'type' => 'text',
                'label' => 'Key Features',
                'input' => 'textarea',
                'backend' => KeyFeatures::class,
                'required' => false,
                'sort_order' => 10,
                'global' => Attribute::SCOPE_GLOBAL,
                'group' => 'Key Features',
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
