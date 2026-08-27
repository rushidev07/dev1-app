<?php

declare(strict_types=1);

namespace Ahy\EstateApiIntegration\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Injects compliance-hold email variables for orders containing
 * magazine or regulated weapon products (detected via the producttype EAV attribute).
 */
class AddProductTypeHoldToOrderEmail implements ObserverInterface
{
    private CartRepositoryInterface $quoteRepository;
    private ProductRepositoryInterface $productRepository;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        ProductRepositoryInterface $productRepository
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->productRepository = $productRepository;
    }

    public function execute(Observer $observer): void
    {
        $transport = $observer->getEvent()->getTransportObject()
            ?: $observer->getEvent()->getTransport();

        if (!$transport || !$transport->getOrder()) {
            return;
        }

        $order = $transport->getOrder();
        $quoteId = (int) $order->getQuoteId();

        if (!$quoteId) {
            return;
        }

        try {
            $quote = $this->quoteRepository->get($quoteId);
        } catch (\Exception $e) {
            return;
        }

        $holdProducts = [];
        $hasHoldProducts = false;

        foreach ($quote->getAllVisibleItems() as $item) {
            // Skip free gifts
            if ((float)$item->getPrice() <= 0 || stripos($item->getSku(), 'FREE') === 0) {
                continue;
            }

            try {
                $product = $this->productRepository->getById((int)$item->getProductId());
                $productType = $product->getAttributeText('producttype');

                if (!$productType || $productType === false) {
                    continue;
                }

                $productType = is_array($productType)
                    ? strtolower(trim(implode(',', $productType)))
                    : strtolower(trim((string)$productType));

                if ($productType !== '') {
                    $hasHoldProducts = true;
                    $holdProducts[] = $item->getName();
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        $transport->setData('has_compliance_hold_products', $hasHoldProducts);
        $transport->setData(
            'compliance_hold_message',
            $hasHoldProducts
                ? 'This order contains magazine or regulated weapon item(s) and has been placed on compliance hold for verification.'
                : ''
        );
        $transport->setData(
            'compliance_hold_products',
            implode('<br/>', $holdProducts)
        );
    }
}
