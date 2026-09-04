<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Checkout\Model\Cart;

use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Cart;
use Magento\Framework\DataObject;

/**
 * Tags a product being added to cart from the PDP "Frequently Bought
 * Together" widget (see view/frontend/templates/product/view/
 * frequently-bought-together.phtml's addBundleToCart()) with the bundle it
 * was added as part of, before Magento adds it - the same
 * addCustomOption()-before-addProduct() mechanism
 * Amasty\Mostviewed\Model\Cart\AddProductsByIds already uses for its own
 * bundle-pack widget, which Magento persists as a normal quote_item_option
 * row (no schema change needed).
 *
 * Consumed by Ahy\PDPRevamp\Plugin\SalesRule\Model\RulesApplier, which
 * applies the real per-item discount during normal totals collection.
 *
 * A no-op for every other add-to-cart action in the store: the two request
 * params this reads are only ever sent by the FBT widget's own JS, and only
 * when 2+ items are being added together.
 */
class TagFbtBundleItem
{
    private const PARAM_BUNDLE_ID = 'ahy_fbt_bundle_id';
    private const PARAM_BUNDLE_SIZE = 'ahy_fbt_bundle_size';

    /**
     * @param mixed $productInfo
     * @param mixed $requestInfo
     */
    public function beforeAddProduct(Cart $subject, $productInfo, $requestInfo = null): array
    {
        if (!$productInfo instanceof Product || $requestInfo === null) {
            return [$productInfo, $requestInfo];
        }

        $bundleId = $this->getParam($requestInfo, self::PARAM_BUNDLE_ID);
        $bundleSize = $this->getParam($requestInfo, self::PARAM_BUNDLE_SIZE);

        if ($bundleId !== null && $bundleSize !== null && (int) $bundleSize >= 2) {
            $productInfo->addCustomOption(self::PARAM_BUNDLE_ID, (string) $bundleId);
            $productInfo->addCustomOption(self::PARAM_BUNDLE_SIZE, (string) (int) $bundleSize);
        }

        return [$productInfo, $requestInfo];
    }

    /**
     * $requestInfo is whatever Magento\Checkout\Controller\Cart\Add passed
     * through - normally the raw $request->getParams() array, but may also
     * arrive as a DataObject depending on the caller.
     *
     * @param mixed $requestInfo
     */
    private function getParam($requestInfo, string $key): ?string
    {
        if ($requestInfo instanceof DataObject) {
            $value = $requestInfo->getData($key);
        } elseif (is_array($requestInfo)) {
            $value = $requestInfo[$key] ?? null;
        } else {
            return null;
        }

        return ($value !== null && $value !== '') ? (string) $value : null;
    }
}
