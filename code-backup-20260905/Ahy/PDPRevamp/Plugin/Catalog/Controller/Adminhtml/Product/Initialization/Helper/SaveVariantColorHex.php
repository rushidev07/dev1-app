<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Catalog\Controller\Adminhtml\Product\Initialization\Helper;

use Ahy\PDPRevamp\Model\ResourceModel\VariantColor;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Persists the "Colour" column's per-row hex value from the Configurations
 * grid into ahy_pdprevamp_variant_color. Reads the same
 * 'configurable-matrix-serialized' request param Magento's own
 * UpdateConfigurations plugin reads (see that class in
 * Magento_ConfigurableProduct), but only touches our own new table -
 * never modifies the product model or any core save behavior.
 */
class SaveVariantColorHex
{
    private const FIELD = 'ahy_color_hex';

    private RequestInterface $request;
    private Json $json;
    private VariantColor $variantColor;

    public function __construct(
        RequestInterface $request,
        Json $json,
        VariantColor $variantColor
    ) {
        $this->request = $request;
        $this->json = $json;
        $this->variantColor = $variantColor;
    }

    public function afterInitialize(Helper $subject, Product $configurableProduct): Product
    {
        $serialized = $this->request->getParam('configurable-matrix-serialized', '[]');
        if (!$serialized) {
            return $configurableProduct;
        }

        try {
            $rows = $this->json->unserialize($serialized);
        } catch (\InvalidArgumentException $e) {
            return $configurableProduct;
        }

        if (!is_array($rows)) {
            return $configurableProduct;
        }

        foreach ($rows as $row) {
            if (empty($row['id']) || !empty($row['newProduct'])) {
                continue;
            }

            $productId = (int) $row['id'];
            $hex = trim((string) ($row[self::FIELD] ?? ''));

            if ($hex === '') {
                $this->variantColor->deleteHex($productId);
                continue;
            }

            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $hex)) {
                $this->variantColor->saveHex($productId, $hex);
            }
        }

        return $configurableProduct;
    }
}
