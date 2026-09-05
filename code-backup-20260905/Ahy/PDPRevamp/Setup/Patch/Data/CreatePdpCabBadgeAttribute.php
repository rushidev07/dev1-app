<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the pdp_cab_badge select attribute admins use to flag which
 * promo badge (if any) shows on a product's card in the "Customers Also
 * Bought" carousel (see Block\Product\View\CustomersAlsoBought and
 * product/view/customers-also-bought.phtml). Options are plain
 * admin-managed attribute options (Stores > Attributes > Product >
 * pdp_cab_badge > Manage Options), same pattern as as_seen_in, so admins
 * can add/rename/remove badge labels without a code deploy - only the
 * label-to-color mapping in CustomersAlsoBought::CAB_BADGE_COLORS needs a
 * matching entry for a new label to render with a specific color.
 */
class CreatePdpCabBadgeAttribute implements DataPatchInterface
{
    public const DEFAULT_OPTIONS = [
        '#1 Paired Item',
        'Trending',
        'Essential',
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

        // The "Badges" attribute group (created by CreatePdpBadgeAttributes)
        // exists on this install with an attribute_group_code that doesn't
        // match what EavSetup::addAttributeGroup() derives from the name
        // ("badges"). Its own exists-check looks the group up BY CODE, so it
        // misses this row and tries to re-insert "Badges" for the same
        // attribute set, hitting the (attribute_set_id, attribute_group_name)
        // unique key. Normalize the code first so the lookup finds the
        // existing row and updates it instead of colliding with it.
        $this->moduleDataSetup->getConnection()->update(
            $this->moduleDataSetup->getTable('eav_attribute_group'),
            ['attribute_group_code' => 'badges'],
            ['attribute_group_name = ?' => 'Badges']
        );

        /** @var \Magento\Eav\Setup\EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $eavSetup->addAttribute(
            Product::ENTITY,
            'pdp_cab_badge',
            [
                'type' => 'varchar',
                'label' => 'Customers Also Bought - Badge',
                'input' => 'select',
                'required' => false,
                'sort_order' => 200,
                'global' => Attribute::SCOPE_GLOBAL,
                'group' => 'Badges',
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
        return [CreatePdpBadgeAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
