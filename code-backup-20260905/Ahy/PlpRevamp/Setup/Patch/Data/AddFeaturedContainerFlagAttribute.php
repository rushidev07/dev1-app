<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Marks a category as an auto-created Featured Products container.
 *
 * Without this flag there is no way to tell a Featured Products category apart
 * from an ordinary one, and both the console command and the save observer would
 * hand it a Featured Products child of its own — recursively, forever.
 *
 * Deliberately invisible on the category form: it is bookkeeping, not something
 * an admin sets.
 */
class AddFeaturedContainerFlagAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'ahy_is_featured_container';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Category::ENTITY, self::ATTRIBUTE_CODE, [
                'type'     => 'int',
                'label'    => 'Is Featured Products Container',
                'input'    => 'boolean',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible'  => false,
                'default'  => '0',
                'note'     => 'Set automatically. Marks a category created to hold Featured Products.',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddFeaturedSourceCategoryAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
