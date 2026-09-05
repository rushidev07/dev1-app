<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\Pricing\SellerResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Records per-line member savings for a completed order, increments the member's
 * LIFETIME cumulative savings (never reset — P0 §1.6), and logs one discount_used
 * activity entry. Idempotent per order. Best-effort: never breaks order placement.
 *
 * Savings per line = regular price − final price paid (incl. any coupon), per the
 * locked decision. member_price (pre-coupon) is stored separately for attribution.
 *
 * EXCLUDED: promotional free-gift lines (e.g. Amasty Free Gift). Those giveaways are
 * granted by store-wide cart rules available to NON-member customer groups too, so
 * their list value is not something the shopper saved *by being a member* — counting
 * it would inflate the lifetime figure used to advertise membership.
 */
class SavingsRecorder
{
    private const SAVINGS_TABLE = 'ahy_caliber_nation_order_savings';

    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly SellerResolver $sellerResolver,
        private readonly ActivityLogger $activityLogger,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {}

    public function record(OrderInterface $order): void
    {
        try {
            if (!$this->config->isPricingEnabled()) {
                return;
            }
            $customerId = (int) $order->getCustomerId();
            if (!$customerId || !$this->memberAccess->isActiveMember($customerId)) {
                return;
            }

            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::SAVINGS_TABLE);
            $orderId = (int) $order->getId();

            // Idempotency — don't double-record if the event fires twice.
            $already = (int) $conn->fetchOne(
                $conn->select()->from($table, ['c' => 'COUNT(*)'])->where('order_id = ?', $orderId)
            );
            if ($already > 0) {
                return;
            }

            $membershipSku = $this->config->getMembershipSku();
            $totalSavings  = 0.0;
            $rows = [];

            foreach ($order->getAllVisibleItems() as $item) {
                if ($item->getSku() === $membershipSku) {
                    continue;
                }
                if ($this->isPromoGiftLine($item)) {
                    continue;
                }
                $qty         = (float) ($item->getQtyOrdered() ?: 1);
                $regularUnit = (float) ($item->getOriginalPrice() ?: $item->getPrice());
                $rowTotal    = (float) $item->getRowTotal();
                $discount    = (float) $item->getDiscountAmount(); // cart-rule / coupon
                $paidRow     = max(0.0, $rowTotal - $discount);
                $paidUnit    = $qty > 0 ? round($paidRow / $qty, 4) : $paidRow;
                $savings     = round(($regularUnit * $qty) - $paidRow, 4);

                if ($savings <= 0) {
                    continue;
                }
                $totalSavings += $savings;

                $rows[] = [
                    'order_id'      => $orderId,
                    'order_item_id' => (int) $item->getItemId(),
                    'customer_id'   => $customerId,
                    'product_id'    => (int) $item->getProductId(),
                    'seller_id'     => $this->sellerResolver->getSellerId((int) $item->getProductId()),
                    'regular_price' => $regularUnit,
                    'member_price'  => (float) $item->getPrice(),
                    'paid_price'    => $paidUnit,
                    'savings'       => $savings,
                    'applied_rules' => null,
                ];
            }

            if (!$rows) {
                return;
            }

            $conn->insertMultiple($table, $rows);
            $this->incrementLifetimeSavings($customerId, $totalSavings);
            $this->activityLogger->log(
                $customerId,
                ActivityLogger::ACTION_DISCOUNT_USED,
                sprintf('member savings $%.2f on order %s', $totalSavings, $order->getIncrementId()),
                $orderId
            );
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] savings record failed: ' . $e->getMessage());
        }
    }

    /**
     * A promotional free-gift line — must NOT count toward member savings.
     *
     * Two independent signals, so this holds even if one is unavailable:
     *  1. The line was given away (nothing paid). A genuine member price can never
     *     reach zero while a global discount cap is configured.
     *  2. The item carries a free-gift rule marker in its buy request
     *     (Amasty Free Gift writes options.ampromo_rule_id). Read as plain data so
     *     this module keeps no hard dependency on the Amasty extension.
     *
     * @param \Magento\Sales\Api\Data\OrderItemInterface $item
     */
    private function isPromoGiftLine($item): bool
    {
        if ((float) $item->getRowTotal() - (float) $item->getDiscountAmount() <= 0.0) {
            return true;
        }

        try {
            $buyRequest = $item->getBuyRequest();
            $options    = $buyRequest ? $buyRequest->getData('options') : null;
            if (\is_array($options) && !empty($options['ampromo_rule_id'])) {
                return true;
            }
        } catch (\Exception $e) {
            // Buy request unavailable / malformed — fall back to the paid-nothing check.
        }

        return false;
    }

    private function incrementLifetimeSavings(int $customerId, float $amount): void
    {
        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
            $membership->setLifetimeSavings(round($membership->getLifetimeSavings() + $amount, 4));
            $this->membershipRepository->save($membership);
        } catch (NoSuchEntityException) {
            // No membership row → nothing to accumulate onto.
        }
    }
}
