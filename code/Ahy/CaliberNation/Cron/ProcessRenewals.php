<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Cron;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\ResourceModel\Membership\CollectionFactory;
use Ahy\CaliberNation\Model\Service\ActivateMembership;
use Ahy\CaliberNation\Model\Service\ActivityLogger;
use Ahy\CaliberNation\Model\Service\ExpiredMemberLocator;
use Ahy\CaliberNation\Model\Service\MembershipEmailService;
use Ahy\CaliberNation\Model\Service\RenewMembership;
use Psr\Log\LoggerInterface;

/**
 * Daily membership lifecycle cron:
 *  1. Auto-renewal — charge the saved card for every membership whose renewal date
 *     has arrived (and retry Renewal Pending), extending on success or failing
 *     → Renewal Pending → Expired per the retry policy.
 *  2. Paid-through expiry — for CANCELLED memberships whose renewal_date has passed,
 *     revert the member group, mark Expired, and email them (config-gated).
 *  3. Non-renewing expiry — for ACTIVE memberships with auto-renew OFF whose
 *     renewal_date has passed (nothing else ever transitions these — step 1 only
 *     looks at auto_renew=1), revert the member group, mark Expired, and email them.
 *  4. Advance renewal reminder — email active auto-renewing members N days before
 *     their renewal date (US advance-notice). Once per cycle.
 *  5. Win-back — email lapsed (Expired) members a rejoin offer once they become
 *     win-back eligible. Once per lapse.
 */
class ProcessRenewals
{
    public function __construct(
        private readonly CollectionFactory $membershipCollectionFactory,
        private readonly RenewMembership $renewMembership,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly ActivateMembership $activateMembership,
        private readonly ActivityLogger $activityLogger,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly MembershipEmailService $emailService,
        private readonly ExpiredMemberLocator $expiredMemberLocator
    ) {}

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->processRenewals($now);
        $this->expireCancelledMemberships($now);
        $this->expireNonRenewingMemberships($now);
        $this->sendRenewalReminders($now);
        $this->sendWinbackEmails();
    }

    private function processRenewals(string $now): void
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('auto_renew', 1)
            ->addFieldToFilter('status', ['in' => [
                MembershipInterface::STATUS_ACTIVE,
                MembershipInterface::STATUS_RENEWAL_PENDING,
            ]])
            ->addFieldToFilter('renewal_date', ['lteq' => $now]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $this->renewMembership->renew($membership);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CaliberNation] Renewal cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        if ($count > 0) {
            $this->logger->info("[CaliberNation] Renewal cron processed {$count} membership(s).");
        }
    }

    /**
     * Paid-through expiry for cancelled memberships whose term has ended.
     */
    private function expireCancelledMemberships(string $now): void
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('status', MembershipInterface::STATUS_CANCELLED)
            ->addFieldToFilter('renewal_date', ['lteq' => $now]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $customerId = (int) $membership->getCustomerId();
                $membership->setStatus(MembershipInterface::STATUS_EXPIRED);
                $this->membershipRepository->save($membership);
                $this->activateMembership->revertMemberGroup($customerId);
                $this->activityLogger->log(
                    $customerId,
                    ActivityLogger::ACTION_STATUS_CHANGE,
                    'cancelled membership reached paid-through date → expired, member group reverted'
                );
                $this->sendExpiryEmail($membership, $customerId);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CaliberNation] Cancelled-expiry cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        if ($count > 0) {
            $this->logger->info("[CaliberNation] Expired {$count} paid-through cancelled membership(s).");
        }
    }

    /**
     * Expiry for members who let auto-renew stay OFF and whose paid term has ended.
     * processRenewals() only ever looks at auto_renew=1, so without this step an
     * active, non-auto-renewing membership past its renewal_date would never
     * transition — the member would keep benefits/pricing indefinitely.
     */
    private function expireNonRenewingMemberships(string $now): void
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('status', MembershipInterface::STATUS_ACTIVE)
            ->addFieldToFilter('auto_renew', 0)
            ->addFieldToFilter('renewal_date', ['lteq' => $now]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $customerId = (int) $membership->getCustomerId();
                $membership->setStatus(MembershipInterface::STATUS_EXPIRED);
                $this->membershipRepository->save($membership);
                $this->activateMembership->revertMemberGroup($customerId);
                $this->activityLogger->log(
                    $customerId,
                    ActivityLogger::ACTION_STATUS_CHANGE,
                    'active non-auto-renewing membership reached renewal date → expired, member group reverted'
                );
                $this->sendExpiryEmail($membership, $customerId);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CaliberNation] Non-renewing-expiry cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        if ($count > 0) {
            $this->logger->info("[CaliberNation] Expired {$count} non-auto-renewing membership(s).");
        }
    }

    /**
     * Advance renewal reminder — active auto-renewing members whose renewal date is
     * within the configured window and who haven't been reminded this cycle.
     */
    private function sendRenewalReminders(string $now): void
    {
        if (!$this->config->isRenewalReminderEnabled()) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime($now . ' +' . $this->config->getRenewalReminderDays() . ' days'));

        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('auto_renew', 1)
            ->addFieldToFilter('status', MembershipInterface::STATUS_ACTIVE)
            ->addFieldToFilter('renewal_date', ['gteq' => $now])
            ->addFieldToFilter('renewal_date', ['lteq' => $cutoff])
            ->addFieldToFilter('renewal_reminder_sent_at', ['null' => true]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $customerId = (int) $membership->getCustomerId();
                $this->emailService->send('caliber_nation_renewal_reminder', $customerId, [
                    'renewal_date' => date('F j, Y', strtotime((string) $membership->getRenewalDate())),
                    'amount'       => '$' . number_format($this->config->getMembershipPrice(), 2),
                    'has_card'     => false,
                ]);
                $membership->setData('renewal_reminder_sent_at', $now);
                $this->membershipRepository->save($membership);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CaliberNation] Renewal-reminder cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        if ($count > 0) {
            $this->logger->info("[CaliberNation] Sent {$count} renewal reminder(s).");
        }
    }

    /**
     * Win-back — expired members who are win-back eligible and haven't been emailed
     * for this lapse. Eligibility (feature enabled + days threshold) is delegated to
     * ExpiredMemberLocator.
     */
    private function sendWinbackEmails(): void
    {
        if (!$this->config->isWinbackEmailEnabled()) {
            return;
        }

        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('status', MembershipInterface::STATUS_EXPIRED)
            ->addFieldToFilter('winback_email_sent_at', ['null' => true]);

        $count = 0;
        foreach ($collection as $membership) {
            try {
                $customerId = (int) $membership->getCustomerId();
                if (!$this->expiredMemberLocator->isWinbackEligible($customerId)) {
                    continue;
                }
                $savings = (float) $membership->getLifetimeSavings();
                $this->emailService->send('caliber_nation_winback', $customerId, [
                    'winback_price' => '$' . number_format($this->config->getWinbackPrice(), 2),
                    'total_savings' => '$' . number_format($savings, 2),
                    'has_savings'   => $savings > 0,
                ]);
                $membership->setData('winback_email_sent_at', date('Y-m-d H:i:s'));
                $this->membershipRepository->save($membership);
                $count++;
            } catch (\Exception $e) {
                $this->logger->error(
                    '[CaliberNation] Win-back cron error for membership '
                    . $membership->getEntityId() . ': ' . $e->getMessage()
                );
            }
        }

        if ($count > 0) {
            $this->logger->info("[CaliberNation] Sent {$count} win-back email(s).");
        }
    }

    /** Expiry notification (config-gated). */
    private function sendExpiryEmail(MembershipInterface $membership, int $customerId): void
    {
        if (!$this->config->isExpiryEmailEnabled()) {
            return;
        }
        $savings = (float) $membership->getLifetimeSavings();
        $this->emailService->send('caliber_nation_expiry', $customerId, [
            'total_savings' => '$' . number_format($savings, 2),
            'has_savings'   => $savings > 0,
        ]);
    }
}
