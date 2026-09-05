<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * CreatePdpAsSeenInAttribute originally created pdp_as_seen_in without a
 * backend model. Multiselect attributes need Magento\Eav\...\ArrayBackend
 * to convert the selected option-id array into a stored comma-separated
 * string - without it, the admin form accepts and "saves" a selection but
 * the value never actually persists. This patch corrects the backend
 * model on environments where the original patch already ran.
 */
class FixPdpAsSeenInAttributeBackend implements DataPatchInterface
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
            'as_seen_in',
            'backend_model',
            ArrayBackend::class
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreatePdpAsSeenInAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
