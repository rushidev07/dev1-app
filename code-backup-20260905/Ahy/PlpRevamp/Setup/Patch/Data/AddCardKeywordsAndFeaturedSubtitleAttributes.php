<?php
declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddCardKeywordsAndFeaturedSubtitleAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_card_keywords')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_card_keywords', [
                'type'     => 'varchar',
                'label'    => 'Subcategory Card Keywords',
                'input'    => 'text',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Short keyword line shown under this category\'s name on the parent category\'s subcategory card. E.g: Tees, polos, performance shirts',
            ]);
        }

        if (!$eavSetup->getAttribute(Category::ENTITY, 'ahy_featured_subtitle')) {
            $eavSetup->addAttribute(Category::ENTITY, 'ahy_featured_subtitle', [
                'type'     => 'varchar',
                'label'    => 'Featured Products Section Subtitle',
                'input'    => 'text',
                'required' => false,
                'global'   => ScopedAttributeInterface::SCOPE_STORE,
                'group'    => 'Display Settings',
                'visible'  => true,
                'default'  => '',
                'note'     => 'Text shown under the Featured Products heading. Leave empty for the default: Handpicked products from our collection.',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [
            AddSubcategoryLayoutAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
