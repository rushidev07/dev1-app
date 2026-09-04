<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Catalog\Controller\Adminhtml\Product\Attribute\Save;

use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Ahy\PDPRevamp\Service\AiColorHexResolver;
use Magento\Catalog\Controller\Adminhtml\Product\Attribute\Save;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Persists edits made in the "Two-Color Swatches" admin panel (see
 * view/adminhtml/layout/catalog_product_attribute_edit.xml and
 * view/adminhtml/templates/catalog/product/attribute/two_color.phtml).
 *
 * Runs AFTER Magento's own attribute save, deliberately - not before. A
 * two-color option's native visual-swatch row is still present (unchanged)
 * in the original "Manage Swatch" grid so nothing looks like it silently
 * disappeared from that grid's data; but a browser's native
 * <input type="color"> cannot hold a "#hex1,#hex2" pair and may coerce it to
 * a default single color on submit, which Magento's own save would then
 * write over our correct pair. Running after guarantees our two-color value
 * is always the final, authoritative write for that option regardless of
 * whatever the native field submitted.
 *
 * Label edits use their own "label" sub-array (option_id => store_id => text)
 * rather than the native grid's "optionvisual[value][...]" field name, and
 * are written directly via NativeColorSwatch::saveOptionLabel() - a
 * deliberately independent read/write path from the native grid's own label
 * fields for that same option (still there, still functional), so editing
 * one can never silently overwrite the other.
 *
 * No-op whenever the request carries no ahy_two_color_swatch rows (every
 * other attribute's save, or a swatch_visual attribute with no two-color
 * options).
 */
class SaveTwoColorSwatch
{
    private const PARAM_NAME = 'ahy_two_color_swatch';

    private RequestInterface $request;
    private AiColorHexResolver $resolver;
    private NativeColorSwatch $nativeColorSwatch;

    public function __construct(
        RequestInterface $request,
        AiColorHexResolver $resolver,
        NativeColorSwatch $nativeColorSwatch
    ) {
        $this->request = $request;
        $this->resolver = $resolver;
        $this->nativeColorSwatch = $nativeColorSwatch;
    }

    public function afterExecute(Save $subject, ResultInterface $result): ResultInterface
    {
        $rows = $this->request->getParam(self::PARAM_NAME);
        if (!is_array($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $optionId = (int) ($row['option_id'] ?? 0);
            if ($optionId < 1) {
                continue;
            }

            $first = (string) ($row['first'] ?? '');
            $second = (string) ($row['second'] ?? '');
            if ($first !== '' && $second !== '') {
                $this->resolver->saveManualTwoColorHex($optionId, $first, $second);
            }

            $labels = $row['label'] ?? null;
            if (is_array($labels)) {
                foreach ($labels as $storeId => $label) {
                    $label = trim((string) $label);
                    if ($label !== '') {
                        $this->nativeColorSwatch->saveOptionLabel($optionId, (int) $storeId, $label);
                    }
                }
            }
        }

        return $result;
    }
}
