<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\Authorizenet\Model\CustomerProfileRepository;
use Ahy\Authorizenet\Service\AuthorizeNetApi;
use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Renews a single membership by charging the saved card (Authorize.Net CIM).
 * Used by the renewal cron and by admin "charge now".
 *
 * Success → extend renewal_date, stay ACTIVE, log, receipt email.
 * Failure → RENEWAL_PENDING; once the configured number of failed attempts is
 * reached (Membership Settings → Max Auto-Renewal Attempts) → EXPIRED + group
 * reverted. Attempts are counted per cycle (only failures logged since the current
 * renewal_date), so every renewal cycle starts with a clean slate.
 *
 * By design a renewal does NOT create a Magento sales order. The financial trail
 * lives in ahy_caliber_nation_renewal_log (amount + Authorize.Net transaction id),
 * the activity log, the receipt email, and the Authorize.Net dashboard. This keeps
 * the renewal path self-contained and free of any payment-method / admin config.
 */
class RenewMembership
{
    private const RENEWAL_TEMPLATE_ID = 'caliber_nation_renewal';
    private const RENEWAL_LOG_TABLE   = 'ahy_caliber_nation_renewal_log';

    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly Config $config,
        private readonly AuthorizeNetApi $authorizeNetApi,
        private readonly CustomerProfileRepository $profileRepository,
        private readonly PaymentTokenRepositoryInterface $paymentTokenRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly ActivateMembership $activateMembership,
        private readonly ResourceConnection $resource,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly MembershipEmailService $emailService
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function renew(MembershipInterface $membership): array
    {
        $customerId = (int) $membership->getCustomerId();
        $tokenId    = (int) $membership->getPaymentTokenId();
        $amount     = $this->config->getMembershipPrice();

        // Resolve CIM profile + payment profile from the saved token.
        $profileId        = $tokenId ? $this->profileRepository->getProfileIdByCustomerId($customerId) : null;
        $paymentProfileId = null;
        if ($tokenId) {
            try {
                $paymentProfileId = $this->paymentTokenRepository->getById($tokenId)->getGatewayToken();
            } catch (\Exception) {
                $paymentProfileId = null;
            }
        }

        if (!$profileId || !$paymentProfileId) {
            return $this->handleFailure($membership, $amount, $tokenId, 'No saved card / CIM profile to charge.');
        }

        // Charge the saved card.
        try {
            $email = $this->customerRepository->getById($customerId)->getEmail();
            $responseJson = $this->authorizeNetApi->chargeSavedCard(
                (string) $profileId,
                (string) $paymentProfileId,
                $amount,
                [],
                [],
                ['customerId' => (string) $customerId, 'email' => $email]
            );
            $response = json_decode((string) $responseJson, true);
        } catch (\Exception $e) {
            return $this->handleFailure($membership, $amount, $tokenId, 'Charge error: ' . $e->getMessage());
        }

        $ok   = ($response['messages']['resultCode'] ?? '') === 'Ok'
             && ($response['transactionResponse']['responseCode'] ?? '') === '1';
        $tran = (string) ($response['transactionResponse']['transId'] ?? '');

        if (!$ok) {
            $err = $response['transactionResponse']['errors'][0]['errorText']
                ?? ($response['messages']['message'][0]['text'] ?? 'Renewal charge declined.');
            return $this->handleFailure($membership, $amount, $tokenId, $err);
        }

        return $this->handleSuccess($membership, $amount, $tokenId, $tran);
    }

    private function handleSuccess(MembershipInterface $membership, float $amount, int $tokenId, string $transId): array
    {
        // Base term only. Signup bonus months are a ONE-TIME incentive granted at
        // activation; including them here would re-grant the bonus on every renewal
        // (13 months of access for a 12-month payment, compounding yearly).
        $months = $this->config->getBaseTermMonths();
        // Extend from the current renewal date to avoid drift.
        $base = $membership->getRenewalDate() ?: date('Y-m-d H:i:s');
        $newRenewal = date('Y-m-d H:i:s', strtotime($base . " +{$months} months"));

        $membership->setStatus(MembershipInterface::STATUS_ACTIVE)
                   ->setRenewalDate($newRenewal);
        // New cycle → allow the advance-notice reminder to fire again.
        $membership->setData('renewal_reminder_sent_at', null);
        $this->membershipRepository->save($membership);

        $customerId = (int) $membership->getCustomerId();

        $this->writeLog((int) $membership->getEntityId(), $customerId, 'success', $amount, $tokenId, $transId, null);
        $this->activityLogger->log($customerId, ActivityLogger::ACTION_RENEWED, 'renewal charged, next=' . $newRenewal);
        $this->sendReceiptEmail($customerId, $amount, $newRenewal, (float) $membership->getLifetimeSavings());

        $this->logger->info("[CaliberNation] Renewal success customer={$customerId} transId={$transId} next={$newRenewal}");
        return ['success' => true, 'message' => 'Membership renewed. Next renewal ' . $newRenewal];
    }

    private function handleFailure(MembershipInterface $membership, float $amount, int $tokenId, string $error): array
    {
        $customerId   = (int) $membership->getCustomerId();
        $membershipId = (int) $membership->getEntityId();

        $this->writeLog($membershipId, $customerId, 'failed', $amount, $tokenId ?: null, null, $error);

        // Failed attempts for THIS renewal cycle only (logged at/after the current
        // renewal_date, which does not move while the membership is pending).
        $failed      = $this->countFailedSince($membershipId, (string) $membership->getRenewalDate());
        $maxAttempts = $this->config->getMaxRenewalAttempts();

        if ($failed >= $maxAttempts) {
            $membership->setStatus(MembershipInterface::STATUS_EXPIRED);
            $this->membershipRepository->save($membership);
            $this->activateMembership->revertMemberGroup($customerId);
            $this->activityLogger->log(
                $customerId,
                ActivityLogger::ACTION_STATUS_CHANGE,
                "expired after {$failed} failed renewal attempt(s) (max {$maxAttempts})"
            );
            $this->logger->warning("[CaliberNation] Membership EXPIRED customer={$customerId} after {$failed}/{$maxAttempts} attempts: {$error}");
            $this->sendExpiryEmail($membership, $customerId);
        } else {
            $membership->setStatus(MembershipInterface::STATUS_RENEWAL_PENDING);
            $this->membershipRepository->save($membership);
            $this->logger->warning("[CaliberNation] Renewal failed (pending, attempt {$failed}/{$maxAttempts}) customer={$customerId}: {$error}");
        }

        return ['success' => false, 'message' => $error];
    }

    /** Notify the member their membership has expired (config-gated). */
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

    // ── helpers ────────────────────────────────────────────────────────────────

    private function writeLog(int $membershipId, int $customerId, string $result, float $amount, ?int $tokenId, ?string $transId, ?string $error, ?int $orderId = null): void
    {
        try {
            $conn = $this->resource->getConnection();
            $conn->insert($this->resource->getTableName(self::RENEWAL_LOG_TABLE), [
                'membership_id'    => $membershipId,
                'customer_id'      => $customerId,
                'result'           => $result,
                'amount'           => $amount,
                'payment_token_id' => $tokenId,
                'transaction_id'   => $transId,
                'order_id'         => $orderId,
                'error_message'    => $error,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] renewal_log write failed: ' . $e->getMessage());
        }
    }

    private function countFailedSince(int $membershipId, string $sinceDate): int
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::RENEWAL_LOG_TABLE);
        $select = $conn->select()
            ->from($table, ['cnt' => 'COUNT(*)'])
            ->where('membership_id = ?', $membershipId)
            ->where('result = ?', 'failed');
        if ($sinceDate) {
            $select->where('created_at >= ?', $sinceDate);
        }
        return (int) $conn->fetchOne($select);
    }

    private function sendReceiptEmail(int $customerId, float $amount, string $renewalDate, float $lifetimeSavings = 0.0): void
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
            $store    = $this->storeManager->getStore();
            $storeId  = (int) $store->getId();

            $this->inlineTranslation->suspend();
            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::RENEWAL_TEMPLATE_ID)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'first_name'    => $customer->getFirstname() ?: 'Member',
                    'amount'        => '$' . number_format($amount, 2),
                    'renewal_date'  => date('F j, Y', strtotime($renewalDate)),
                    'store_name'    => $store->getName(),
                    'account_url'   => $this->urlBuilder->getUrl('customer/account'),
                    'total_savings' => '$' . number_format($lifetimeSavings, 2),
                    'has_savings'   => $lifetimeSavings > 0,
                ])
                ->setFromByScope($this->config->getEmailSender((string) $storeId) ?: 'general', $storeId)
                ->addTo($customer->getEmail())
                ->getTransport();
            $transport->sendMessage();
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] renewal receipt email failed: ' . $e->getMessage());
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
