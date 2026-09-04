<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Renames the pdp_descriptors attribute's admin label to "AI Descriptors" -
 * attribute code and behavior are unchanged, this only updates what admins
 * see on the product edit form. A separate patch because
 * CreatePdpDescriptorsAttribute already ran on installs where the attribute
 * exists, and patches never re-run.
 */
class UpdatePdpDescriptorsAttributeLabel implements DataPatchInterface
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

        $eavSetup->updateAttribute(
            Product::ENTITY,
            'pdp_descriptors',
            'frontend_label',
            'AI Descriptors'
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreatePdpDescriptorsAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
