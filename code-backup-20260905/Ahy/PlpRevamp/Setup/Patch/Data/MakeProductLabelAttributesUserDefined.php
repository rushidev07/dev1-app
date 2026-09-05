<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Unlocks "Manage Options" for the product-label attributes.
 *
 * EavSetup defaults is_user_defined to 0 (Eav PropertyMapper: 'is_user_defined' =>
 * $this->_getValue($input, 'user_defined', 0)), and AddProductLabelAttributes never
 * passed it — so both attributes were created as SYSTEM attributes.
 *
 * Magento then disables the whole option grid, per
 * Magento\Eav\Block\Adminhtml\Attribute\Edit\Options\Options::canManageOptionDefaultOnly():
 *
 *     !$attribute->getCanManageOptionLabels()
 *         && !$attribute->getIsUserDefined()
 *         && $attribute->getSourceModel()
 *
 * All three were true, so options.phtml rendered every input disabled and hid the
 * Add Option button. Note this only started biting once ConvertProductLabelToSelect
 * assigned a source model — the third condition — which is why the field behaved
 * fine while it was still free text.
 *
 * Flipping the flag changes nothing about the stored options or product values; it
 * only tells Magento the admin is allowed to edit them.
 */
class MakeProductLabelAttributesUserDefined implements DataPatchInterface
{
    private const ATTRIBUTE_CODES = [
        'ahy_product_label',
        'ahy_product_label_color',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach (self::ATTRIBUTE_CODES as $code) {
            if (!$eavSetup->getAttribute(Product::ENTITY, $code)) {
                continue;
            }

            // Unconditional: the point is to overwrite the 0 that EavSetup defaulted in.
            $eavSetup->updateAttribute(Product::ENTITY, $code, 'is_user_defined', 1);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [ConvertProductLabelToSelect::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
