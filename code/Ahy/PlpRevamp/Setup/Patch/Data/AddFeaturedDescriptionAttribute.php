<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Free-text blurb rendered under the Featured Products grid. Empty = section hidden.
 */
class AddFeaturedDescriptionAttribute implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'ahy_featured_description';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Category::ENTITY, self::ATTRIBUTE_CODE, [
                'type'         => 'text',
                'label'        => 'Featured Products Description',
                'input'        => 'textarea',
                'required'     => false,
                'global'       => ScopedAttributeInterface::SCOPE_STORE,
                'group'        => 'Display Settings',
                'visible'      => true,
                // Admin-managed content, editable in the admin like any other
                // attribute — see AddProductLabelAttributes for what happens when
                // this is left to default to 0.
                'user_defined' => true,
                'default'      => '',
                'note'         => 'Paragraph shown under the Featured Products grid, with a See more link. Leave empty to hide it.',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddSubcategoryLayoutAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
