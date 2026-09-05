<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Catalog\Model\Product\Attribute\DataProvider;

use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Magento\Catalog\Model\Product\Attribute\DataProvider;
use Magento\Framework\App\RequestInterface;

/**
 * Feeds the "Two-Color Swatches" admin panel (see
 * view/adminhtml/ui_component/product_attribute_add_form.xml) its rows -
 * every option on the current attribute with a comma-paired native swatch
 * value. Reads the attribute id the same way the form's own dataSource does
 * (requestFieldName "id", see product_attribute_add_form.xml's
 * dataProvider settings).
 */
class AddTwoColorSwatchData
{
    private const DATA_KEY = 'ahy_two_color_swatch';
    private const REQUEST_FIELD = 'id';

    private NativeColorSwatch $nativeColorSwatch;
    private RequestInterface $request;

    public function __construct(NativeColorSwatch $nativeColorSwatch, RequestInterface $request)
    {
        $this->nativeColorSwatch = $nativeColorSwatch;
        $this->request = $request;
    }

    public function afterGetData(DataProvider $subject, array $result): array
    {
        $attributeId = (int) $this->request->getParam(self::REQUEST_FIELD);
        if ($attributeId < 1 || !isset($result[$attributeId]) || !is_array($result[$attributeId])) {
            return $result;
        }

        $result[$attributeId][self::DATA_KEY] = $this->nativeColorSwatch->getTwoColorOptionsForAttribute($attributeId);

        return $result;
    }
}
