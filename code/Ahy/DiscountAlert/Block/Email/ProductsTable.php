<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Block\Email;

use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the product rows of the alert email.
 */
class ProductsTable extends Template
{
    protected $_template = 'Ahy_DiscountAlert::email/products_table.phtml';

    public function __construct(
        Context $context,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Display-ready rows, so the template holds markup only.
     *
     * @return array<int, array{sku: string, name: string, price: string, special_price: string, discount: string}>
     */
    public function getRows(): array
    {
        $rows = [];

        foreach ((array) $this->getData('products') as $product) {
            $sku = (string) ($product[Collector::KEY_SKU] ?? '');

            $rows[] = [
                'sku'           => $sku,
                'name'          => $this->resolveName($product, $sku),
                'price'         => $this->formatPrice($product[Collector::KEY_PRICE] ?? 0),
                'special_price' => $this->formatPrice($product[Collector::KEY_SPECIAL_PRICE] ?? 0),
                'discount'      => \number_format((float) ($product[Collector::KEY_DISCOUNT_PCT] ?? 0), 2) . '%',
            ];
        }

        return $rows;
    }

    /**
     * Product names can be stored HTML-encoded; decode first so the template escapes
     * exactly once rather than rendering "&amp;amp;".
     *
     * @param array<string, mixed> $product
     */
    private function resolveName(array $product, string $fallback): string
    {
        $name = (string) ($product[Collector::KEY_NAME] ?? '');

        if ($name === '') {
            $name = $fallback;
        }

        return \html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Formatted in the store's own currency rather than assuming a symbol.
     */
    private function formatPrice(mixed $value): string
    {
        return (string) $this->priceCurrency->format((float) $value, false);
    }
}
