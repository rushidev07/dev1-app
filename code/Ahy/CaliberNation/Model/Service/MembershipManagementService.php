<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Psr\Log\LoggerInterface;

class MembershipManagementService
{
    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly LoggerInterface $logger,
        private readonly ActivityLogger $activityLogger,
        private readonly \Ahy\CaliberNation\Model\Config $config,
        private readonly MembershipEmailService $emailService,
        private readonly PaymentTokenManagementInterface $paymentTokenManagement,
        private readonly RefundRequestService $refundRequestService
    ) {}

    // ── Cancel membership ─────────────────────────────────────────────────────

    /**
     * Cancels the customer's membership.
     * - Sets status to "cancelled" and disables auto-renew.
     * - Returns ['success' => bool, 'message' => string].
     */
    public function cancelMembership(int $customerId): array
    {
        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return $this->result(false, 'No membership found for this account.');
        }

        if ($membership->getStatus() === MembershipInterface::STATUS_CANCELLED) {
            return $this->result(false, 'Your membership is already cancelled.');
        }

        try {
            $membership->setStatus(MembershipInterface::STATUS_CANCELLED);
            $membership->setAutoRenew(0);
            $this->membershipRepository->save($membership);
        } catch (CouldNotSaveException $e) {
            $this->logger->error('[CaliberNation] cancelMembership failed for customer ' . $customerId . ': ' . $e->getMessage());
            return $this->result(false, 'Could not cancel membership. Please try again.');
        }

        // Paid-through: keep member benefits (customer group) until renewal_date; the
        // group is reverted + status set to expired by the daily cron once that date
        // passes (see Cron\ProcessRenewals). No further auto-renew charge.
        $this->activityLogger->log(
            $customerId,
            ActivityLogger::ACTION_CANCELLED,
            'self-service cancellation (paid-through until ' . ($membership->getRenewalDate() ?: 'n/a') . ')'
        );

        $this->refundRequestService->createIfEligible(
            $customerId,
            (int) $membership->getEntityId(),
            $membership->getStartDate()
        );

        $this->logger->info('[CaliberNation] Membership cancelled (paid-through) for customer ' . $customerId);

        if ($this->config->isCancellationEmailEnabled()) {
            $savings = (float) $membership->getLifetimeSavings();
            $this->emailService->send('caliber_nation_cancellation', $customerId, [
                'end_date'      => $membership->getRenewalDate()
                    ? date('F j, Y', strtotime((string) $membership->getRenewalDate())) : '',
                'total_savings' => '$' . number_format($savings, 2),
                'has_savings'   => $savings > 0,
            ]);
        }

        return $this->result(true, $this->config->getCancellationConfirmationMessage());
    }

    // ── Resume a cancelled membership (still inside the paid-through window) ──

    /**
     * Undo a cancellation while the member is STILL inside the term they paid for.
     *
     * This is deliberately NOT the rejoin/activation path: the member never lost
     * their benefits (MemberAccess keeps cancelled members entitled until
     * renewal_date), so we must not reset start_date or push renewal_date out by
     * another full term — that would hand them a free year. All this does is flip
     * the status back to active and, when a usable card is on file, switch
     * auto-renew back on so the term actually rolls over.
     *
     * Returns ['success' => bool, 'message' => string, 'auto_renew' => bool].
     */
    public function resumeMembership(int $customerId): array
    {
        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return $this->result(false, 'No membership found for this account.');
        }

        if ($membership->getStatus() !== MembershipInterface::STATUS_CANCELLED) {
            return $this->result(false, 'Your membership is not cancelled.');
        }

        if ($this->refundRequestService->isRefundReviewed((int) $membership->getEntityId())) {
            return $this->result(false, 'Your refund has already been processed. Please contact support to rejoin.');
        }

        $renewal = $membership->getRenewalDate();
        if (!$renewal || strtotime((string) $renewal) <= time()) {
            // Term already over — this is a genuine rejoin (new paid term), not a resume.
            return $this->result(false, 'Your membership term has ended. Please rejoin to start a new term.');
        }

        // Only restore auto-renew when a still-chargeable card is bound; otherwise the
        // membership simply runs to its paid-through date (card ⟺ auto-renew invariant).
        $boundToken = $this->findUsableToken($customerId, (int) $membership->getPaymentTokenId());
        $autoRenew  = $boundToken !== null;

        try {
            $membership->setStatus(MembershipInterface::STATUS_ACTIVE);
            $membership->setAutoRenew($autoRenew ? 1 : 0);
            $this->membershipRepository->save($membership);
        } catch (CouldNotSaveException $e) {
            $this->logger->error('[CaliberNation] resumeMembership failed for customer ' . $customerId . ': ' . $e->getMessage());
            return $this->result(false, 'Could not restore your membership. Please try again.');
        }

        $this->refundRequestService->markReinstated((int) $membership->getEntityId());

        $this->activityLogger->log(
            $customerId,
            ActivityLogger::ACTION_STATUS_CHANGE,
            'cancellation undone within paid-through window (renewal ' . $renewal
                . ', auto_renew=' . ($autoRenew ? '1' : '0') . ')'
        );
        $this->logger->info('[CaliberNation] Cancellation undone for customer ' . $customerId);

        $endDate = date('F j, Y', strtotime((string) $renewal));
        $message = $autoRenew
            ? "Your membership is active again and will renew automatically on {$endDate}."
            : "Your membership is active again through {$endDate}. Add a saved card to turn on automatic renewal.";

        return array_merge($this->result(true, $message), ['auto_renew' => $autoRenew]);
    }

    // ── Toggle auto-renew ─────────────────────────────────────────────────────

    /**
     * Enables or disables auto-renew for the customer's membership.
     *
     * Enabling is guarded: a membership can only auto-renew against a usable saved
     * card. If a valid card is already bound it enables directly; otherwise the
     * caller must pass $selectedTokenId (an explicit card choice). With no usable
     * card at all it is rejected.
     *
     * Returns ['success' => bool, 'message' => string] plus, on the enable-blocked
     * paths, 'needs_card' => true (no cards on file) or 'needs_choice' => true
     * (cards exist but none bound → show the picker).
     */
    public function toggleAutoRenew(int $customerId, bool $enable, ?int $selectedTokenId = null): array
    {
        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return $this->result(false, 'No membership found for this account.');
        }

        if ((bool) $membership->getAutoRenew() === $enable) {
            $label = $enable ? 'already enabled' : 'already disabled';
            return $this->result(false, "Auto-renew is {$label}.");
        }

        $logDetail = 'disabled';

        if ($enable) {
            if (empty($this->paymentTokenManagement->getVisibleAvailableTokens($customerId))) {
                return array_merge(
                    $this->result(false, 'Add a saved card before enabling auto-renew.'),
                    ['needs_card' => true]
                );
            }

            // Enable directly only if a still-usable card is already bound.
            $boundToken = $this->findUsableToken($customerId, (int) $membership->getPaymentTokenId());
            if (!$boundToken) {
                $chosen = $this->findUsableToken($customerId, $selectedTokenId);
                if (!$chosen) {
                    return array_merge(
                        $this->result(false, 'Please choose a card to renew with.'),
                        ['needs_choice' => true]
                    );
                }
                $membership->setPaymentTokenId((int) $chosen->getEntityId());
                $boundToken = $chosen;
            }
            $logDetail = 'enabled, card ' . $this->cardLabel($boundToken);
        } else {
            // Invariant: no auto-renew → no bound renewal card. Clearing it means the
            // UI never shows a "renewal card" that isn't actually going to be charged.
            $membership->setPaymentTokenId(null);
        }

        try {
            $membership->setAutoRenew((int) $enable);
            $this->membershipRepository->save($membership);
        } catch (CouldNotSaveException $e) {
            $this->logger->error('[CaliberNation] toggleAutoRenew failed for customer ' . $customerId . ': ' . $e->getMessage());
            return $this->result(false, 'Could not update auto-renew setting. Please try again.');
        }

        $this->activityLogger->log($customerId, ActivityLogger::ACTION_AUTO_RENEW, $logDetail);
        $this->logger->info("[CaliberNation] Auto-renew {$logDetail} for customer {$customerId}");

        $userLabel = $enable ? 'enabled' : 'disabled';
        return $this->result(true, "Auto-renew has been {$userLabel}.");
    }

    /**
     * Binds a saved card as the membership's renewal card. Assigning a card implies
     * intent to auto-renew, so this also ensures auto-renew is ON (invariant:
     * a bound renewal card ⟺ auto-renew enabled). Validates the token is the
     * customer's own and usable.
     */
    public function setRenewalCard(int $customerId, int $selectedTokenId): array
    {
        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return $this->result(false, 'No membership found for this account.');
        }

        $chosen = $this->findUsableToken($customerId, $selectedTokenId);
        if (!$chosen) {
            return $this->result(false, 'Please choose a valid saved card.');
        }
        if ((int) $membership->getPaymentTokenId() === (int) $chosen->getEntityId()
            && (int) $membership->getAutoRenew() === 1) {
            return $this->result(false, 'That card is already your renewal card.');
        }

        try {
            $membership->setPaymentTokenId((int) $chosen->getEntityId());
            $membership->setAutoRenew(1); // assigning a card turns auto-renew on
            $this->membershipRepository->save($membership);
        } catch (CouldNotSaveException $e) {
            $this->logger->error('[CaliberNation] setRenewalCard failed for customer ' . $customerId . ': ' . $e->getMessage());
            return $this->result(false, 'Could not update your renewal card. Please try again.');
        }

        $label = $this->cardLabel($chosen);
        $this->activityLogger->log($customerId, ActivityLogger::ACTION_AUTO_RENEW, 'renewal card set to ' . $label);
        return $this->result(true, "Renewal card updated to {$label}. Auto-renew is on.");
    }

    /**
     * The customer's usable saved cards for the renewal picker: active, visible,
     * non-expired Vault tokens (same set the account "Saved Payment Methods" shows).
     * Returns [['id' => int, 'label' => string], ...].
     */
    public function getUsableCards(int $customerId): array
    {
        $cards = [];
        foreach ($this->paymentTokenManagement->getVisibleAvailableTokens($customerId) as $token) {
            $cards[] = [
                'id'    => (int) $token->getEntityId(),
                'label' => $this->cardLabel($token),
            ];
        }
        return $cards;
    }

    /** Resolve a usable token by id, scoped to the customer (ownership + usability check). */
    private function findUsableToken(int $customerId, ?int $tokenId): ?PaymentTokenInterface
    {
        if (!$tokenId) {
            return null;
        }
        foreach ($this->paymentTokenManagement->getVisibleAvailableTokens($customerId) as $token) {
            if ((int) $token->getEntityId() === $tokenId) {
                return $token;
            }
        }
        return null;
    }

    /** Human label for a card, e.g. "VISA ••1111 · Exp 07/2029". */
    private function cardLabel(PaymentTokenInterface $token): string
    {
        $details = json_decode((string) $token->getTokenDetails(), true) ?: [];
        $type    = $details['type'] ?? 'Card';
        $masked  = $details['maskedCC'] ?? '';
        $exp     = $details['expirationDate'] ?? '';

        $label = trim($type . ($masked !== '' ? ' ••' . $masked : ''));
        return $exp !== '' ? $label . ' · Exp ' . $exp : $label;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function result(bool $success, string $message): array
    {
        return ['success' => $success, 'message' => $message];
    }
}
