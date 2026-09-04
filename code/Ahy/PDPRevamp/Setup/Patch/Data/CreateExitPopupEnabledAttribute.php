<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "Enable Exit Intent Popup" product attribute.
 *
 * The popup used to render on every PDP - etc/config.xml defaults the global
 * switch to 1 and ExitIntentPopup::isEnabled() was the only gate - so it had no
 * way to be limited to particular products. This attribute is that gate, and
 * isEnabled() now requires both it and the global switch, which stays as a
 * store-wide kill switch.
 *
 * Default No is deliberate: defaulting to Yes would silently keep today's
 * every-product behaviour the moment this ships.
 *
 * The same attribute is reused by UpdateExitPopupSalesRule as the coupon's
 * actions filter, so one flag decides both where the popup appears and which
 * products the discount may apply to.
 *
 * used_for_promo_rules is what makes it selectable in a Cart Price Rule's
 * conditions/actions - without it the rule cannot reference the attribute at all.
 */
class CreateExitPopupEnabledAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'pdp_exit_popup_enabled';

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
                'label' => 'Enable Exit Intent Popup',
                'input' => 'boolean',
                'source' => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                'required' => false,
                'default' => 0,
                'sort_order' => 20,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'group' => 'Key Features',
                'visible' => true,
                'user_defined' => true,
                // Required for the Cart Price Rule actions filter to see it.
                'used_for_promo_rules' => true,
                'is_used_in_grid' => true,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => true,
                'note' => 'Show the exit-intent discount popup on this product, and allow the popup coupon to discount it.',
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
