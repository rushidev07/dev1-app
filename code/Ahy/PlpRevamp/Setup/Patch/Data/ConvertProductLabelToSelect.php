<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Turns `ahy_product_label` from a free-text field into a dropdown with admin-managed options.
 *
 * The attribute keeps its varchar backend (same as the sibling `ahy_product_label_color`), so only
 * `frontend_input` and the source model change and no value has to move between EAV tables. What
 * *does* change is the meaning of the stored value: option IDs instead of the typed text. Any label
 * an admin already typed is therefore turned into an attribute option and the stored text rewritten
 * to that option's ID, so existing badges keep rendering.
 *
 * New labels are added in Stores > Attributes > Product > ahy_product_label > Manage Options —
 * no code change needed.
 */
class ConvertProductLabelToSelect implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'ahy_product_label';

    /** Options created on top of whatever labels are already in use. */
    private const SEED_OPTIONS = [
        'Best Seller',
        'Free Shipping',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup    = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $attributeId = (int)$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);

        // Attribute missing entirely — AddProductLabelAttributes has not run; nothing to convert.
        if (!$attributeId) {
            return $this;
        }

        $existingLabels = $this->fetchStoredValues($attributeId);
        $optionMap      = $this->fetchOptionMap($attributeId);

        // Labels needing an option: the seed list plus every value an admin typed while the field
        // was free text. Numeric values are skipped — they are already option IDs (re-run safety).
        $missing = [];
        foreach ([...self::SEED_OPTIONS, ...$existingLabels] as $label) {
            $label = trim($label);
            if ($label === '' || ctype_digit($label)) {
                continue;
            }
            $key = mb_strtolower($label);
            if (isset($optionMap[$key]) || isset($missing[$key])) {
                continue;
            }
            $missing[$key] = $label;
        }

        if ($missing !== []) {
            $values = [];
            foreach (array_values($missing) as $i => $label) {
                // 0 => admin store label; Magento creates one option per 'option_N' entry.
                $values['option_' . $i] = [0 => $label];
            }
            $eavSetup->addAttributeOption(['attribute_id' => $attributeId, 'value' => $values]);

            // Re-read so the freshly created option IDs are available for the value rewrite below.
            $optionMap = $this->fetchOptionMap($attributeId);
        }

        $this->rewriteStoredValuesToOptionIds($attributeId, $existingLabels, $optionMap);

        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'frontend_input', 'select');
        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'source_model', Table::class);
        $eavSetup->updateAttribute(
            Product::ENTITY,
            self::ATTRIBUTE_CODE,
            'note',
            'Badge shown on the product card. Manage the available labels under Stores > Attributes > Product > ahy_product_label. Leave empty for no badge.'
        );

        return $this;
    }

    /**
     * Distinct raw values currently stored for the attribute, across all store scopes.
     *
     * @return string[]
     */
    private function fetchStoredValues(int $attributeId): array
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->distinct()
            ->from($this->moduleDataSetup->getTable('catalog_product_entity_varchar'), ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('value IS NOT NULL')
            ->where('TRIM(value) <> ?', '');

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * Existing options as lowercased label => option_id.
     *
     * @return array<string, int>
     */
    private function fetchOptionMap(int $attributeId): array
    {
        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()
            ->from(['o' => $this->moduleDataSetup->getTable('eav_attribute_option')], ['option_id'])
            ->join(
                ['ov' => $this->moduleDataSetup->getTable('eav_attribute_option_value')],
                'ov.option_id = o.option_id AND ov.store_id = 0',
                ['value']
            )
            ->where('o.attribute_id = ?', $attributeId);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[mb_strtolower(trim((string)$row['value']))] = (int)$row['option_id'];
        }

        return $map;
    }

    /**
     * Replaces typed text with the matching option ID so existing badges survive the conversion.
     *
     * @param string[] $storedValues
     * @param array<string, int> $optionMap
     */
    private function rewriteStoredValuesToOptionIds(int $attributeId, array $storedValues, array $optionMap): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table      = $this->moduleDataSetup->getTable('catalog_product_entity_varchar');

        foreach ($storedValues as $value) {
            $trimmed = trim($value);
            if ($trimmed === '' || ctype_digit($trimmed)) {
                continue; // already an option ID
            }

            $optionId = $optionMap[mb_strtolower($trimmed)] ?? null;
            if ($optionId === null) {
                continue;
            }

            $connection->update(
                $table,
                ['value' => (string)$optionId],
                ['attribute_id = ?' => $attributeId, 'value = ?' => $value]
            );
        }
    }

    public static function getDependencies(): array
    {
        return [
            AddProductLabelAttributes::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
