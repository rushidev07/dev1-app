<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\Data\MembershipInterfaceFactory;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single activation point for a Caliber Nation membership. Called by:
 *  - the order-placement observer (in-cart purchase + programmatic landing order),
 *  - the rejoin flow (via MembershipSignupService).
 *
 * Responsibilities: create/update the membership row, assign the member customer
 * group, store the vault token, log the activity, and send the welcome email.
 */
class ActivateMembership
{
    private const WELCOME_TEMPLATE_ID = 'caliber_nation_welcome';
    private const DEFAULT_GROUP_ID     = 1; // Magento "General" group — used on revert

    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly MembershipInterfaceFactory $membershipFactory,
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Activate (or reactivate) a membership for a customer.
     *
     * @throws \Exception on unrecoverable failure (caller decides how to surface)
     */
    public function activate(
        int $customerId,
        string $tier = MembershipInterface::TIER_ANNUAL,
        ?int $paymentTokenId = null,
        ?int $orderId = null
    ): MembershipInterface {
        $now         = date('Y-m-d H:i:s');
        $months      = $this->config->getMembershipDurationMonths();
        $renewalDate = date('Y-m-d H:i:s', strtotime("+{$months} months"));

        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            $membership = $this->membershipFactory->create();
            $membership->setCustomerId($customerId);
        }

        $membership->setStatus(MembershipInterface::STATUS_ACTIVE)
            ->setTier($tier)
            ->setStartDate($now)
            ->setRenewalDate($renewalDate)
            ->setIsTrial($this->config->isTrialEnabled() ? 1 : 0);

        // Fresh active cycle → reset lifecycle-email dedup so reminders / win-back
        // can fire again for this (re)activation.
        $membership->setData('renewal_reminder_sent_at', null)
            ->setData('winback_email_sent_at', null);

        // Only overwrite the stored card when a new one is supplied (rejoin passes
        // null to keep the previously-bound card).
        if ($paymentTokenId !== null) {
            $membership->setPaymentTokenId($paymentTokenId);
        }

        // Enforce the card ⟺ auto-renew invariant at the activation layer: auto-renew
        // is only ever ON when a usable card is actually bound. A member who opts out
        // of saving a card at checkout gets an active membership with auto-renew OFF
        // and no stale card — matching the toggle guard on the account page.
        $membership->setAutoRenew($membership->getPaymentTokenId() ? 1 : 0);

        $this->membershipRepository->save($membership);

        $this->assignMemberGroup($customerId);
        $this->activityLogger->log(
            $customerId,
            ActivityLogger::ACTION_CREATED,
            'tier=' . $tier . ' renewal=' . $renewalDate,
            $orderId
        );
        $this->sendWelcomeEmail($customerId, $tier, $renewalDate);

        return $membership;
    }

    /**
     * Move the customer into the Caliber Nation member group.
     */
    public function assignMemberGroup(int $customerId): void
    {
        $groupId = $this->config->getMemberGroupId();
        if ($groupId <= 0) {
            $this->logger->warning('[CaliberNation] member_group_id not configured; skipping group assignment.');
            return;
        }
        $this->setCustomerGroup($customerId, $groupId);
    }

    /**
     * Revert the customer to the default (non-member) group — used on cancellation/expiry.
     */
    public function revertMemberGroup(int $customerId): void
    {
        $this->setCustomerGroup($customerId, self::DEFAULT_GROUP_ID);
    }

    private function setCustomerGroup(int $customerId, int $groupId): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            if ((int) $customer->getGroupId() !== $groupId) {
                $customer->setGroupId($groupId);
                $this->customerRepository->save($customer);
            }
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] setCustomerGroup failed for ' . $customerId . ': ' . $e->getMessage());
        }
    }

    private function sendWelcomeEmail(int $customerId, string $tier, string $renewalDate): void
    {
        try {
            $customer  = $this->customerRepository->getById($customerId);
            $store     = $this->storeManager->getStore();
            $storeId   = (int) $store->getId();
            $tierLabel = $tier === MembershipInterface::TIER_ANNUAL ? 'Caliber Annual Membership' : ucfirst($tier) . ' Membership';

            $this->inlineTranslation->suspend();
            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::WELCOME_TEMPLATE_ID)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'first_name'      => $customer->getFirstname() ?: 'Member',
                    'tier_label'      => $tierLabel,
                    'renewal_date'    => date('F j, Y', strtotime($renewalDate)),
                    'store_name'      => $store->getName(),
                    'store_url'       => $this->urlBuilder->getBaseUrl(),
                    'account_url'     => $this->urlBuilder->getUrl('customer/account'),
                    'savings_message' => $this->config->getSavingsMessage((string) $storeId),
                ])
                ->setFromByScope($this->config->getEmailSender((string) $storeId) ?: 'general', $storeId)
                ->addTo($customer->getEmail())
                ->getTransport();
            $transport->sendMessage();
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] welcome email failed: ' . $e->getMessage());
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
