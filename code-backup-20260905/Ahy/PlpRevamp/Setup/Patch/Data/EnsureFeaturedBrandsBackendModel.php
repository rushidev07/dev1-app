<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Ahy\PlpRevamp\Model\Category\Attribute\Backend\FeaturedBrands;
use Magento\Catalog\Model\Category;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Reattaches the FeaturedBrands backend model to `ahy_featured_brands`.
 *
 * The attribute was created WITH the backend model, but eav_attribute.backend_model
 * was found NULL on dev1 — so neither beforeSave() nor afterLoad() ran. The
 * consequences are silent and look like unrelated UI bugs:
 *
 *   - afterLoad() never decodes the stored JSON, so the admin form is handed a raw
 *     string. dynamicRows cannot render a string as rows and shows an empty list.
 *   - beforeSave() never serialises the posted rows, so EAV cannot write the array
 *     to a text column. The save appears to succeed while the old value stays put,
 *     which reads to an admin as "my brand disappeared".
 *
 * The earlier patches all guard with `if (!getAttribute(...))`, so once the
 * attribute exists none of them can repair its definition — hence this one, which
 * updates unconditionally.
 */
class EnsureFeaturedBrandsBackendModel implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'ahy_featured_brands';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // Nothing to repair if the attribute was never created; the earlier patches
        // own that case.
        if (!$eavSetup->getAttribute(Category::ENTITY, self::ATTRIBUTE_CODE)) {
            return $this;
        }

        // Unconditional: the whole point is to overwrite a NULL/incorrect value.
        $eavSetup->updateAttribute(
            Category::ENTITY,
            self::ATTRIBUTE_CODE,
            'backend_model',
            FeaturedBrands::class
        );

        // The rows are stored as a JSON string, which needs the text backend type.
        $eavSetup->updateAttribute(
            Category::ENTITY,
            self::ATTRIBUTE_CODE,
            'backend_type',
            'text'
        );

        return $this;
    }

    public static function getDependencies(): array
    {
        return [EnsureFeaturedBrandsAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
