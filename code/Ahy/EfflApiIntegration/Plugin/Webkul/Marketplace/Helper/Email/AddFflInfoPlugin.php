<?php

declare(strict_types=1);

namespace Ahy\EfflApiIntegration\Plugin\Webkul\Marketplace\Helper\Email;

use Ahy\EfflApiIntegration\Logger\Logger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Webkul\Marketplace\Helper\Email as MpEmailHelper;
use Webkul\Marketplace\Model\SaleslistFactory;

/**
 * Adds FFL dealer info (has_ffl/has_normal/ffl_dealer/ffl_dealer_id) to the Webkul
 * Marketplace seller-notification emails, without modifying the vendor module.
 */
class AddFflInfoPlugin
{
    private const FREE_DECAL_SKU = 'FREE Everest Decal';

    private OrderRepositoryInterface $orderRepository;
    private SaleslistFactory $saleslistFactory;
    private ProductRepositoryInterface $productRepository;
    private Logger $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SaleslistFactory $saleslistFactory,
        ProductRepositoryInterface $productRepository,
        Logger $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->saleslistFactory = $saleslistFactory;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function beforeSendInvoicedOrderEmail(
        MpEmailHelper $subject,
        $emailTemplateVariables,
        $senderInfo,
        $receiverInfo
    ) {
        return [$this->addFflInfo($emailTemplateVariables), $senderInfo, $receiverInfo];
    }

    public function beforeSendPlacedOrderEmail(
        MpEmailHelper $subject,
        $emailTemplateVariables,
        $senderInfo,
        $receiverInfo
    ) {
        return [$this->addFflInfo($emailTemplateVariables), $senderInfo, $receiverInfo];
    }

    private function addFflInfo(array $emailTemplateVariables): array
    {
        $emailTemplateVariables['ffl_dealer'] = null;
        $emailTemplateVariables['ffl_dealer_id'] = null;
        $emailTemplateVariables['has_ffl'] = false;
        $emailTemplateVariables['has_normal'] = false;

        $orderId = $emailTemplateVariables['order_id'] ?? null;
        $sellerId = $emailTemplateVariables['seller_id'] ?? null;

        if (!$orderId || !$sellerId) {
            return $emailTemplateVariables;
        }

        try {
            $order = $this->orderRepository->get($orderId);
            $emailTemplateVariables['ffl_dealer'] = $order->getData('ffl_dealer');
            $emailTemplateVariables['ffl_dealer_id'] = $order->getData('selected_ffl_dealer_id');

            $hasFfl = false;
            $hasNormal = false;

            $sellerItems = $this->saleslistFactory->create()
                ->getCollection()
                ->addFieldToFilter('order_id', $orderId)
                ->addFieldToFilter('seller_id', $sellerId)
                ->addFieldToFilter('parent_item_id', ['null' => 'true']);

            foreach ($sellerItems as $sellerItem) {
                try {
                    $product = $this->productRepository->getById($sellerItem->getMageproductId());
                } catch (\Exception $e) {
                    continue;
                }

                $sku = $product ? $product->getSku() : $sellerItem->getMageSku();
                if ($sku === self::FREE_DECAL_SKU) {
                    continue;
                }

                $attr = $product ? $product->getResource()->getAttribute('ffl_selection_required') : false;
                if ($attr) {
                    $fflRequired = $product->getData('ffl_selection_required');
                    if (!empty($fflRequired)) {
                        $hasFfl = true;
                    } else {
                        $hasNormal = true;
                    }
                } else {
                    $hasNormal = true;
                }

                if ($hasFfl && $hasNormal) {
                    break;
                }
            }

            $emailTemplateVariables['has_ffl'] = $hasFfl;
            $emailTemplateVariables['has_normal'] = $hasNormal;
        } catch (\Exception $e) {
            $this->logger->warning(
                'AddFflInfoPlugin: unable to compute FFL email info for order_id=' . $orderId .
                ', seller_id=' . $sellerId . ': ' . $e->getMessage()
            );
        }

        return $emailTemplateVariables;
    }
}
