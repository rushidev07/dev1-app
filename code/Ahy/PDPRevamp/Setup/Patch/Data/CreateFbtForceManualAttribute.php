<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds "Always Use My FBT Picks" - the per-product escape from co-purchase
 * priority.
 *
 * The FBT section prefers real co-purchase data (tier 1) over an admin's manual
 * picks (tier 2). That is the intended order, but it means a merchant who has
 * deliberately curated a bundle can be silently overridden the moment the product
 * accumulates order history. This flag lets them opt that product out: when set,
 * tier 1 is skipped and their picks are used.
 *
 * Default No, so the co-purchase-first behaviour holds everywhere it has not been
 * explicitly turned off.
 *
 * Note the deliberate sharp edge, spelled out in the field's note: Yes with no
 * picks set renders nothing at all rather than falling back to co-purchase. That
 * is the honest reading of "always use my picks", but it is easy to trip over.
 */
class CreateFbtForceManualAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'pdp_fbt_force_manual';

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
                'type' => 'int',
                'label' => 'Always Use My FBT Picks',
                'input' => 'boolean',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                'required' => false,
                'default' => 0,
                'sort_order' => 25,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group' => 'Key Features',
                'visible' => true,
                'user_defined' => true,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => true,
                'note' => 'Yes: always show the products chosen in "Frequently Bought Together (Manual)" '
                    . 'below, ignoring purchase history. If that list is empty the section will not '
                    . 'appear at all. No (default): purchase history is used when available, and the '
                    . 'manual list is the fallback.',
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
