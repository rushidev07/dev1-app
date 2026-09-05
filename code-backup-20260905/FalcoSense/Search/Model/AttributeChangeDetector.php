<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model;

use Magento\Catalog\Model\Product;

class AttributeChangeDetector
{
    private const WATCHED = ['name', 'price', 'special_price', 'status', 'url_key'];

    /**
     * Returns true if any search-relevant attribute changed, or if it is a new product.
     * Stock changes (qty / in_stock) are handled separately via StockChangeObserver.
     */
    public function hasRelevantChange(Product $product): bool
    {
        // New product — no original data means it was just created
        if (!$product->getOrigData()) {
            return true;
        }

        foreach (self::WATCHED as $attr) {
            $orig = (string) $product->getOrigData($attr);
            $curr = (string) $product->getData($attr);
            if ($orig !== $curr) {
                return true;
            }
        }

        return false;
    }
}
