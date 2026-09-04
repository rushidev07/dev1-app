<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the pdp_descriptors text attribute admins use to set the short
 * punchy phrase shown under the product title on the PDP (e.g. "Ultralight.
 * Waterproof. Trail-Ready."), see product/view/product-info.phtml and
 * product/product-detail-page.phtml. Manually admin-entered for now; a
 * future AI step can populate this same attribute from the product
 * description without any template change. When empty, the PDP falls back
 * to the existing short-description text.
 */
class CreatePdpDescriptorsAttribute implements DataPatchInterface
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
            'pdp_descriptors',
            [
                'type' => 'varchar',
                'label' => 'AI Descriptors',
                'input' => 'text',
                'required' => false,
                'sort_order' => 210,
                'global' => Attribute::SCOPE_GLOBAL,
                'group' => 'General',
                'visible' => true,
                'user_defined' => true,
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'note' => 'Short phrase shown under the product title on the PDP (e.g. "Ultralight. Waterproof. Trail-Ready."). Leave blank to fall back to the short description.',
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
