<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Observer;

use Ahy\PDPRevamp\Model\ResourceModel\ExitCoupon;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Attaches a customer id to an exit-popup claim that was made while logged out.
 *
 * The popup deliberately lets guests claim - that is the whole point of the exit
 * intent - so the claim row is written with customer_id NULL and only the email
 * to identify it. Controller\Index\Subscribe fills the id in itself, but only
 * when the claimant is *already* signed in, which is the less common path. The
 * common one is: guest claims, then registers or signs in a minute later. Nothing
 * closed that gap, so those rows kept customer_id NULL forever even once the
 * matching account existed.
 *
 * Two consequences, neither of them a security hole but both real:
 *
 * - customerHasClaim() looks the customer up by id, so a returning customer was
 *   invisible to it. The unique index on email still blocked a second claim, and
 *   Magento's own uses_per_customer still blocked a second redemption, so nobody
 *   could actually double-dip. What broke was the *check* - it silently fell
 *   through to the email index instead of answering.
 * - The admin claims grid cannot report which claims converted, and
 *   is_redeemed can never be attributed, without an id to join on.
 *
 * Bound to both register and login because either can be the first moment the
 * email and an account id are known together:
 *
 * - customer_register_success - guest claims, then creates an account.
 * - customer_login - guest claims on a device where they are signed out, then
 *   signs in to an account they already had.
 *
 * Matching on email is what makes this work at all, and it is safe here because
 * both events fire only after Magento has established ownership of the address:
 * registration verifies it is unused, login verifies the password. We are not
 * trusting a user-supplied string.
 *
 * linkCustomerByEmail() only updates rows where customer_id IS NULL, so a claim
 * already attributed to one account is never silently reassigned to another, and
 * re-running on every login is a no-op after the first.
 */
class LinkExitCouponClaimToCustomer implements ObserverInterface
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
            $customer = $observer->getEvent()->getData('customer');

            if ($customer === null) {
                return;
            }

            $email = (string) $customer->getEmail();
            $customerId = (int) $customer->getId();

            if ($email === '' || $customerId < 1) {
                return;
            }

            $this->exitCoupon->linkCustomerByEmail($email, $customerId);
        } catch (\Throwable $exception) {
            // Backfilling an id is bookkeeping. It must never be able to fail a
            // registration or block a login, so this swallows rather than throws.
            $this->logger->error(
                '[PDPRevamp] could not link exit-popup claim to customer: '
                . $exception->getMessage()
            );
        }
    }
}
