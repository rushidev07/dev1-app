<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier;

use Ahy\PDPRevamp\Model\CssColorMap;
use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Ahy\PDPRevamp\Model\ResourceModel\VariantColor;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Ui\DataProvider\Product\Form\Modifier\ConfigurablePanel;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

/**
 * Adds an admin-editable "Colour" column to the Configurations
 * ("Current Variations") grid, backed by ahy_pdprevamp_variant_color
 * (see VariantColor resource model) rather than a product attribute -
 * this is the exact hex the storefront swatch circle uses for that
 * specific simple product variant. Runs after
 * Magento\ConfigurableProduct\Ui\DataProvider\Product\Form\Modifier\Composite
 * (sortOrder 140 in that module's di.xml), which is what actually builds
 * the 'configurable-matrix' data/meta this class extends.
 *
 * When no hex has been explicitly set for a variant yet, the field is
 * pre-filled with a best guess, tried in order:
 *  1. The Color attribute option's own already-resolved native swatch
 *     (eav_attribute_option_swatch - the same value the storefront and the
 *     "Two-Color Swatches" admin panel already use, whether AI-resolved or
 *     admin-set). A two-color pair passes through as "hex1,hex2" - the
 *     field's own template (view/adminhtml/web/template/form/element/
 *     color-picker.html) renders that as a diagonal split preview, the
 *     same visual language the storefront itself already uses, since a
 *     plain <input type="color"> can't represent a split at all. An
 *     uploaded image can't be represented either way, so that case is
 *     skipped here.
 *  2. The option's own label, only when it matches a recognized CSS color
 *     name (e.g. "Red" -> #FF0000).
 * So the picker doesn't default to a misleading black when nothing has
 * been explicitly set per-variant yet. This is only a starting point - the
 * admin can still override it (which replaces a two-color preview with one
 * explicit single hex for that variant), and only what they actually save
 * gets persisted to ahy_pdprevamp_variant_color.
 */
class VariantColorColumn implements ModifierInterface
{
    private const DATA_SCOPE = 'ahy_color_hex';
    private const COLOR_ATTRIBUTE_CODE = 'color';

    private VariantColor $variantColor;
    private EavConfig $eavConfig;
    private CssColorMap $cssColorMap;
    private NativeColorSwatch $nativeColorSwatch;

    public function __construct(
        VariantColor $variantColor,
        EavConfig $eavConfig,
        CssColorMap $cssColorMap,
        NativeColorSwatch $nativeColorSwatch
    ) {
        $this->variantColor = $variantColor;
        $this->eavConfig = $eavConfig;
        $this->cssColorMap = $cssColorMap;
        $this->nativeColorSwatch = $nativeColorSwatch;
    }

    public function modifyData(array $data): array
    {
        foreach ($data as $productId => $productData) {
            if (!isset($productData['configurable-matrix']) || !is_array($productData['configurable-matrix'])) {
                continue;
            }

            $rows = $productData['configurable-matrix'];
            $variantIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
            $hexByVariantId = $this->variantColor->getHexByProductIds(array_filter($variantIds));

            $optionIdByRowIndex = array_map(fn (array $row): ?int => $this->getColorOptionId($row), $rows);
            $swatchHexByOptionId = $this->nativeColorSwatch->getHexByOptionIds(
                array_values(array_filter($optionIdByRowIndex, static fn (?int $id): bool => $id !== null))
            );

            foreach ($rows as $index => $row) {
                $variantId = (int) ($row['id'] ?? 0);
                $hex = $hexByVariantId[$variantId] ?? '';
                if ($hex === '') {
                    $hex = $this->guessHex($optionIdByRowIndex[$index], $swatchHexByOptionId);
                }
                $rows[$index][self::DATA_SCOPE] = $hex;
            }

            $data[$productId]['configurable-matrix'] = $rows;
        }

        return $data;
    }

    /**
     * Best-guess hex for the variant's own Color option, tried in order:
     * the option's already-resolved native swatch, then its label against
     * known CSS color names. Returns '' if neither yields anything.
     *
     * @param array<int, string> $swatchHexByOptionId pre-fetched for every
     *  option on the current matrix, to avoid a query per row
     */
    private function guessHex(?int $optionId, array $swatchHexByOptionId): string
    {
        if ($optionId === null) {
            return '';
        }

        $hex = $this->hexFromAttributeSwatchValue($swatchHexByOptionId[$optionId] ?? '');
        if ($hex !== '') {
            return $hex;
        }

        return $this->hexFromColorLabel($optionId);
    }

    private function getColorOptionId(array $row): ?int
    {
        $configurableAttribute = $row['configurable_attribute'] ?? null;
        if (!$configurableAttribute) {
            return null;
        }

        $decoded = json_decode((string) $configurableAttribute, true);
        if (!is_array($decoded) || empty($decoded[self::COLOR_ATTRIBUTE_CODE])) {
            return null;
        }

        return (int) $decoded[self::COLOR_ATTRIBUTE_CODE];
    }

    /**
     * The option's own native swatch value (eav_attribute_option_swatch) -
     * the same value already shown/edited in "Manage Swatch" and the
     * "Two-Color Swatches" panel, whether AI-resolved or admin-set. A
     * two-color pair passes through as "hex1,hex2" so the field's own
     * template can render the diagonal split; an uploaded image can't be
     * represented by this field at all (single OR two-color), so that
     * case falls through to the CSS label guess instead.
     */
    private function hexFromAttributeSwatchValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $parts = array_map('trim', explode(',', $value, 2));
        foreach ($parts as $part) {
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $part) !== 1) {
                return '';
            }
        }

        return implode(',', $parts);
    }

    /**
     * Best-guess hex derived from the option's own label, only when that
     * label matches a recognized CSS color name. Returns '' (no guess) if
     * the label can't be resolved or doesn't match.
     */
    private function hexFromColorLabel(int $optionId): string
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::COLOR_ATTRIBUTE_CODE);
            if (!$attribute || !$attribute->usesSource()) {
                return '';
            }
            $label = $attribute->getSource()->getOptionText($optionId);
        } catch (\Throwable $e) {
            return '';
        }

        if (!is_string($label) || $label === '') {
            return '';
        }

        $normalized = CssColorMap::normalize($label);

        return $this->cssColorMap->getHex($normalized) ?? '';
    }

    public function modifyMeta(array $meta): array
    {
        $path = [
            ConfigurablePanel::GROUP_CONFIGURABLE,
            'children',
            ConfigurablePanel::CONFIGURABLE_MATRIX,
            'children',
            'record',
            'children',
        ];

        $recordChildren = &$this->getByPath($meta, $path);
        if ($recordChildren === null) {
            // Configurations panel isn't present for this product (e.g. not a configurable product) - nothing to add.
            return $meta;
        }

        // Pin explicit sort orders on the vendor's own columns so our new
        // field lands in a known position (right after "Attributes",
        // before "Actions") instead of at the front - the vendor never
        // sets sortOrder on these containers itself, and an unordered
        // sibling always sorts after any sibling that does have one.
        $vendorColumnOrder = [
            'thumbnail_image_container' => 10,
            'name_container' => 20,
            'sku_container' => 30,
            'price_container' => 40,
            'quantity_container' => 50,
            'price_weight' => 60,
            'status' => 70,
            'attributes' => 80,
            'actionsList' => 100,
        ];
        foreach ($vendorColumnOrder as $childKey => $sortOrder) {
            if (isset($recordChildren[$childKey]['arguments']['data']['config'])) {
                $recordChildren[$childKey]['arguments']['data']['config']['sortOrder'] = $sortOrder;
            }
        }

        $recordChildren['colour_container'] = [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement' => 'input',
                        'component' => 'Ahy_PDPRevamp/js/form/element/color-picker',
                        'elementTmpl' => 'Ahy_PDPRevamp/form/element/color-picker',
                        'dataType' => 'text',
                        'dataScope' => self::DATA_SCOPE,
                        'label' => __('Colour'),
                        'fit' => true,
                        'sortOrder' => 90,
                    ],
                ],
            ],
        ];

        return $meta;
    }

    /**
     * @param array $data
     * @param string[] $path
     * @return array|null
     */
    private function &getByPath(array &$data, array $path)
    {
        $current = &$data;
        foreach ($path as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $null = null;
                return $null;
            }
            $current = &$current[$key];
        }
        return $current;
    }
}
