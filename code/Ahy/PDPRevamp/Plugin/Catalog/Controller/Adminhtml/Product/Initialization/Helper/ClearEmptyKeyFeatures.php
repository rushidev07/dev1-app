<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Catalog\Controller\Adminhtml\Product\Initialization\Helper;

use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;

/**
 * Deleting the last "Key Features" row leaves nothing for the browser to
 * submit for product[key_features] at all - bracket-notation form params
 * can't represent "explicitly emptied array" versus "key never touched",
 * so by the time Magento's normal EAV attribute save runs, an absent key
 * just leaves the product's previously-loaded value in place instead of
 * clearing it.
 *
 * The dynamicRows field (Ui/DataProvider/Product/Form/Modifier/
 * KeyFeatures.php) always renders a sibling hidden "key_features_marker"
 * input alongside the grid - a static field, not a row, so it keeps
 * submitting even when the grid itself has zero rows. Marker present +
 * key_features missing means the grid really was emptied, so clear it
 * explicitly before the standard attribute save runs.
 */
class ClearEmptyKeyFeatures
{
    private RequestInterface $request;

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    public function afterInitialize(Helper $subject, Product $product): Product
    {
        $productData = $this->request->getParam('product');

        if (is_array($productData)
            && array_key_exists('key_features_marker', $productData)
            && !array_key_exists('key_features', $productData)
        ) {
            $product->setData('key_features', []);
        }

        return $product;
    }
}
