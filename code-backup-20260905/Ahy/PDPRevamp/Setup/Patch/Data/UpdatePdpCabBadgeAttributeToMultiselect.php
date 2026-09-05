<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Converts pdp_cab_badge (see CreatePdpCabBadgeAttribute) from a single-select
 * to a multiselect, so a product can carry more than one badge at once (e.g.
 * both "Trending" and "Essential") instead of being limited to exactly one.
 * Existing single-value data is unaffected - a lone option id is already
 * valid multiselect storage, it just isn't comma-joined with anything else.
 */
class UpdatePdpCabBadgeAttributeToMultiselect implements DataPatchInterface
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
        $eavSetup->updateAttribute(Product::ENTITY, 'pdp_cab_badge', 'input', 'multiselect');

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [CreatePdpCabBadgeAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
