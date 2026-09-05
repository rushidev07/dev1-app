<?php

declare(strict_types=1);

namespace Ahy\EstateApiIntegration\Plugin;

use Magento\Sales\Model\Order;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Ahy\EstateApiIntegration\Logger\Logger;

/**
 * Holds orders containing magazine or regulated weapon products as compliance_hold.
 * Runs independently of the Orchid restriction hold plugin.
 */
class ProductTypeHoldPlugin
{
    private Logger $logger;
    private ProductRepositoryInterface $productRepository;

    private const HOLD_STATUS = 'compliance_hold';

    private array $skipHoldStates = [
        Order::STATE_COMPLETE,
        Order::STATE_CLOSED,
        Order::STATE_CANCELED,
    ];

    private array $alreadyHeldStatuses = [
        'compliance_hold',
        'orchid_fail_restriction',
        'partially_on_hold',
    ];

    public function __construct(
        Logger $logger,
        ProductRepositoryInterface $productRepository
    ) {
        $this->logger = $logger;
        $this->productRepository = $productRepository;
    }

    public function beforeSave(Order $order)
    {
        $this->logger->info("[ProductTypeHold] Plugin triggered for Order ID: " . $order->getIncrementId());

        // Skip terminal states
        if (in_array($order->getState(), $this->skipHoldStates, true)) {
            $this->logger->info("[ProductTypeHold] Order in terminal state, skipping.");
            return;
        }

        // Skip when order is being processed for fulfillment (shipment or invoice creation).
        // Magento sets is_in_process=true before calling $order->save() in these flows,
        // so we must not re-apply a compliance hold at that point.
        if ($order->getIsInProcess()) {
            $this->logger->info("[ProductTypeHold] Order is in-process (shipment/invoice), skipping re-hold.");
            return;
        }

        // Skip if already held by this or the Orchid plugin
        if ($order->getState() === Order::STATE_HOLDED
            || in_array($order->getStatus(), $this->alreadyHeldStatuses, true)
        ) {
            $this->logger->info("[ProductTypeHold] Order already held (state: {$order->getState()}, status: {$order->getStatus()}), skipping.");
            return;
        }

        $items = $order->getItems();
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            // Skip free gift items
            if ((float)$item->getPrice() === 0.0) {
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

                if ($productType === '') {
                    continue;
                }

                // Any non-empty producttype value means magazine or regulated weapon
                $this->logger->info(sprintf(
                    "[ProductTypeHold] Restricted product detected: SKU=%s, Type=%s, OrderID=%s",
                    $item->getSku(),
                    $productType,
                    $order->getIncrementId()
                ));

                if ($order->canHold()) {
                    $order->hold();
                    $this->logger->info("[ProductTypeHold] Order held successfully.");
                }

                $order->setStatus(self::HOLD_STATUS);
                $this->logger->info("[ProductTypeHold] Status set to " . self::HOLD_STATUS);
                return;
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    "[ProductTypeHold] Failed to check product %s: %s",
                    $item->getProductId(),
                    $e->getMessage()
                ));
            }
        }
    }
}