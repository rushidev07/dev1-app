<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Observer;

use Ahy\PDPRevamp\Model\ResourceModel\ExitCoupon;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Flags an exit-popup claim as redeemed once an order actually uses its code.
 *
 * Without this, is_redeemed stays 0 for the lifetime of every row, so the admin
 * claims grid can only ever report "claimed" and never "converted" - which is the
 * one number the grid exists to answer.
 *
 * Bound to sales_order_place_after rather than an invoice or payment event: the
 * coupon has been consumed by the time the order is placed (Magento has already
 * incremented salesrule_coupon.times_used), so a later payment failure does not
 * hand the code back. Recording redemption at placement therefore matches what
 * the coupon table itself believes.
 *
 * The order's coupon_code is filtered through our own table by
 * markRedeemed()'s WHERE clause, so orders using any other rule's coupon simply
 * match no row and cost one indexed lookup.
 */
class MarkExitCouponRedeemed implements ObserverInterface
{
    private ExitCoupon $exitCoupon;
    private LoggerInterface $logger;

    public function __construct(ExitCoupon $exitCoupon, LoggerInterface $logger)
    {
        $this->exitCoupon = $exitCoupon;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');

            if ($order === null) {
                return;
            }

            $couponCode = trim((string) $order->getCouponCode());

            if ($couponCode === '') {
                return;
            }

            $this->exitCoupon->markRedeemed($couponCode);

            // A guest checkout can be the first point at which the claim's email
            // and a customer id are known together - the account may have been
            // created during checkout. Link it here too, so the grid can attribute
            // the conversion.
            $customerId = (int) $order->getCustomerId();
            $email = (string) $order->getCustomerEmail();

            if ($customerId > 0 && $email !== '') {
                $this->exitCoupon->linkCustomerByEmail($email, $customerId);
            }
        } catch (\Throwable $exception) {
            // Must never fail order placement - the order is far more important
            // than this flag.
            $this->logger->error(
                '[PDPRevamp] could not mark exit-popup claim redeemed: '
                . $exception->getMessage()
            );
        }
    }
}
