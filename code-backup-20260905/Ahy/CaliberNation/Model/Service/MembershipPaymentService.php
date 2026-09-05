<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\Authorizenet\Model\CustomerProfileRepository;
use Ahy\Authorizenet\Service\AuthorizeNetApi;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\PaymentTokenFactory;
use Psr\Log\LoggerInterface;

/**
 * Handles payment charging and card vault storage for Caliber Nation membership signup.
 *
 * Responsibilities:
 *  - Server-side card validation (Luhn, expiry, CVV)
 *  - Authorize.Net auth+capture via AuthorizeNetApi
 *  - CIM customer profile creation / extension
 *  - Vault token persistence (always saved — auto-renewal requires it)
 *
 * This service is intentionally independent of the checkout session, SavedCC feature
 * flag, and the "save card" checkbox. Card storage is mandatory for membership auto-renewal.
 */
class MembershipPaymentService
{
    private const PAYMENT_METHOD_CODE = 'authnetahypayment';
    private const VALIDATION_MODE     = 'liveMode';

    public function __construct(
        private readonly AuthorizeNetApi                $authorizeNetApi,
        private readonly CustomerProfileRepository      $profileRepository,
        private readonly PaymentTokenFactory            $paymentTokenFactory,
        private readonly PaymentTokenRepositoryInterface $paymentTokenRepository,
        private readonly SearchCriteriaBuilder          $searchCriteriaBuilder,
        private readonly LoggerInterface                $logger
    ) {}

    /**
     * Charge the membership fee and save the card to vault for future auto-renewal.
     *
     * @return array{success: true, vault_token_id: int|null, transaction_id: string}
     *            | array{success: false, message: string}
     */
    public function charge(
        int    $customerId,
        string $email,
        string $firstName,
        string $lastName,
        string $cardNumber,
        string $expiryMonth,
        string $expiryYear,
        string $cvv,
        string $billingZip,
        float  $amount
    ): array {
        // ── 1. Server-side validation ─────────────────────────────────────────
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
            return $this->error('Invalid card number length. Please check and try again.');
        }

        if (!$this->luhnCheck($cardNumber)) {
            return $this->error('Invalid card number. Please check and try again.');
        }

        $month = (int) $expiryMonth;
        $year  = (int) $expiryYear;
        $nowY  = (int) date('Y');
        $nowM  = (int) date('n');

        if ($month < 1 || $month > 12) {
            return $this->error('Invalid expiry month. Please check and try again.');
        }
        if ($year < $nowY || ($year === $nowY && $month < $nowM)) {
            return $this->error('Your card has expired. Please use a different card.');
        }

        $cvvLen = strlen(trim($cvv));
        if ($cvvLen < 3 || $cvvLen > 4) {
            return $this->error('Invalid CVV. Please check and try again.');
        }

        $billingZip = trim($billingZip);
        if (empty($billingZip)) {
            return $this->error('Billing zip code is required.');
        }

        // ── 2. Build address arrays ───────────────────────────────────────────
        $monthPadded = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        $billingAddress = [
            'firstName'    => $firstName,
            'lastName'     => $lastName,
            'street'       => 'Not Available',
            'city'         => 'Not Available',
            'region'       => 'Not Available',
            'postcode'     => $billingZip,
            'country_code' => 'US',
            'telephone'    => 'Not Available',
        ];

        $customerDetails = [
            'customerId' => (string) $customerId,
            'email'      => $email,
        ];

        // ── 3. Auth + Capture ─────────────────────────────────────────────────
        $this->logger->info("[MembershipPayment] Initiating charge. customerId={$customerId}, amount={$amount}");

        try {
            $responseJson = $this->authorizeNetApi->createCharge(
                $cardNumber,
                $monthPadded,
                (string) $year,
                $cvv,
                $amount,
                [],              // no shipping — virtual product
                $billingAddress,
                $customerDetails
            );
        } catch (\Exception $e) {
            $this->logger->error('[MembershipPayment] createCharge exception: ' . $e->getMessage());
            return $this->error('Payment could not be processed. Please try again.');
        }

        $response = json_decode((string) $responseJson, true);

        if (!$this->isChargeSuccessful($response)) {
            $errorText = $response['transactionResponse']['errors'][0]['errorText']
                ?? ($response['messages']['message'][0]['text'] ?? 'Payment declined. Please try a different card.');
            $this->logger->warning('[MembershipPayment] Charge failed: ' . $errorText . ' customerId=' . $customerId);
            return $this->error($errorText);
        }

        $transactionId = (string) ($response['transactionResponse']['transId'] ?? '');
        $this->logger->info("[MembershipPayment] Charge successful. transId={$transactionId}, customerId={$customerId}");

        // ── 4. Card metadata ──────────────────────────────────────────────────
        $last4    = substr($cardNumber, -4);
        $cardType = $this->detectCardType($cardNumber);

        // ── 5. CIM profile: create new or add to existing ────────────────────
        $paymentProfileId = $this->createOrExtendCimProfile(
            $customerId,
            $email,
            $firstName,
            $lastName,
            $billingZip,
            $cardNumber,
            $monthPadded,
            (string) $year,
            $transactionId
        );

        if (!$paymentProfileId) {
            // Payment succeeded but vault save failed — non-fatal, log and continue.
            // Membership will be created but without a stored payment token.
            $this->logger->error(
                "[MembershipPayment] Vault save failed for customerId={$customerId}. " .
                "Membership created without payment_token_id."
            );
            return [
                'success'        => true,
                'vault_token_id' => null,
                'transaction_id' => $transactionId,
            ];
        }

        // ── 6. Save vault token ───────────────────────────────────────────────
        $vaultTokenId = $this->saveVaultToken(
            $customerId,
            $paymentProfileId,
            $last4,
            $monthPadded,
            (string) $year,
            $cardType
        );

        $this->logger->info("[MembershipPayment] Complete. customerId={$customerId}, vaultTokenId={$vaultTokenId}");

        return [
            'success'        => true,
            'vault_token_id' => $vaultTokenId,
            'transaction_id' => $transactionId,
        ];
    }

    // ── Private: Authorize.Net helpers ────────────────────────────────────────

    private function isChargeSuccessful(?array $response): bool
    {
        if (!$response) {
            return false;
        }
        $resultCode   = $response['messages']['resultCode'] ?? '';
        $responseCode = $response['transactionResponse']['responseCode'] ?? '';
        return $resultCode === 'Ok' && $responseCode === '1';
    }

    /**
     * If the customer already has a CIM profile, add a payment profile to it.
     * Otherwise create a new CIM profile from the transaction that was just charged.
     *
     * Returns the Authorize.Net customerPaymentProfileId, or null on failure.
     */
    private function createOrExtendCimProfile(
        int    $customerId,
        string $email,
        string $firstName,
        string $lastName,
        string $billingZip,
        string $cardNumber,
        string $expiryMonth,
        string $expiryYear,
        string $transactionId
    ): ?string {
        $existingProfileId = $this->profileRepository->getProfileIdByCustomerId($customerId);

        if ($existingProfileId) {
            // Customer already has a CIM profile — add a new payment profile to it
            $profileData = [
                'billTo' => [
                    'firstName'   => $firstName,
                    'lastName'    => $lastName,
                    'address'     => 'Not Available',
                    'city'        => 'Not Available',
                    'state'       => 'Not Available',
                    'zip'         => $billingZip,
                    'country'     => 'US',
                    'phoneNumber' => 'Not Available',
                ],
                'payment' => [
                    'creditCard' => [
                        'cardNumber'     => $cardNumber,
                        'expirationDate' => $expiryYear . '-' . $expiryMonth,
                    ],
                ],
            ];

            $result = $this->authorizeNetApi->createCustomerPaymentProfile(
                $existingProfileId,
                $profileData,
                self::VALIDATION_MODE
            );

            if (!empty($result['customerPaymentProfileId'])) {
                $this->logger->info(
                    "[MembershipPayment] Added payment profile to existing CIM profile. " .
                    "profileId={$existingProfileId}, paymentProfileId={$result['customerPaymentProfileId']}"
                );
                return (string) $result['customerPaymentProfileId'];
            }

            $this->logger->error(
                '[MembershipPayment] createCustomerPaymentProfile failed: ' .
                ($result['message'] ?? 'unknown error')
            );
            return null;
        }

        // No existing CIM profile — create one from the completed transaction
        if (empty($transactionId)) {
            $this->logger->error('[MembershipPayment] No transactionId available to create CIM profile.');
            return null;
        }

        try {
            $responseJson = $this->authorizeNetApi->createCustomerProfileFromTransaction(
                $transactionId,
                $email,
                (string) $customerId
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[MembershipPayment] createCustomerProfileFromTransaction exception: ' . $e->getMessage()
            );
            return null;
        }

        $profileResponse = json_decode((string) $responseJson, true);

        if (($profileResponse['messages']['resultCode'] ?? '') !== 'Ok') {
            $this->logger->error(
                '[MembershipPayment] createCustomerProfileFromTransaction failed: ' .
                json_encode($profileResponse)
            );
            return null;
        }

        $customerProfileId = $profileResponse['customerProfileId'] ?? null;
        $paymentProfileIds = $profileResponse['customerPaymentProfileIdList'] ?? [];

        if ($customerProfileId && !$this->profileRepository->hasProfile($customerId)) {
            $this->profileRepository->saveProfileMapping($customerId, (string) $customerProfileId);
            $this->logger->info("[MembershipPayment] Saved new CIM profile mapping. profileId={$customerProfileId}");
        }

        $paymentProfileId = !empty($paymentProfileIds) ? (string) $paymentProfileIds[0] : null;

        if (!$paymentProfileId) {
            $this->logger->error('[MembershipPayment] CIM profile created but no payment profile IDs returned.');
        }

        return $paymentProfileId;
    }

    // ── Private: Vault helpers ────────────────────────────────────────────────

    /**
     * Saves a vault token for the customer. Returns the entity_id.
     * If an identical card already exists in the vault, returns its existing entity_id instead.
     */
    private function saveVaultToken(
        int    $customerId,
        string $gatewayToken,
        string $last4,
        string $expiryMonth,
        string $expiryYear,
        string $cardType
    ): ?int {
        $existingId = $this->getExistingVaultTokenId($customerId, $last4, $expiryMonth, $expiryYear, $cardType);
        if ($existingId !== null) {
            $this->logger->info("[MembershipPayment] Duplicate card found in vault. Reusing entity_id={$existingId}");
            return $existingId;
        }

        try {
            $expiresAt = $this->buildExpirationTimestamp($expiryMonth, $expiryYear);

            /** @var \Magento\Vault\Api\Data\PaymentTokenInterface $token */
            $token = $this->paymentTokenFactory->create();
            $token->setCustomerId($customerId);
            $token->setPaymentMethodCode(self::PAYMENT_METHOD_CODE);
            $token->setGatewayToken($gatewayToken);
            $token->setIsActive(true);
            $token->setIsVisible(true);
            $token->setType('card');
            $token->setExpiresAt($expiresAt);
            $token->setPublicHash(hash('sha256', self::PAYMENT_METHOD_CODE . $gatewayToken . $customerId));
            $token->setTokenDetails(json_encode([
                'type'           => strtoupper($cardType),
                'maskedCC'       => $last4,
                'expirationDate' => sprintf('%02d/%s', (int) $expiryMonth, $expiryYear),
            ]));

            $this->paymentTokenRepository->save($token);

            $entityId = (int) $token->getEntityId();
            $this->logger->info("[MembershipPayment] Vault token saved. entity_id={$entityId}, customerId={$customerId}");
            return $entityId;
        } catch (\Exception $e) {
            $this->logger->error('[MembershipPayment] saveVaultToken error: ' . $e->getMessage());
            return null;
        }
    }

    private function getExistingVaultTokenId(
        int    $customerId,
        string $last4,
        string $expiryMonth,
        string $expiryYear,
        string $cardType
    ): ?int {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('is_active', 1)
            ->create();

        $tokens          = $this->paymentTokenRepository->getList($criteria)->getItems();
        $formattedExpiry = sprintf('%02d/%s', (int) $expiryMonth, $expiryYear);

        foreach ($tokens as $token) {
            /** @var PaymentTokenInterface $token */
            $details = json_decode($token->getTokenDetails() ?? '[]', true);
            if (
                ($details['maskedCC'] ?? '') === $last4 &&
                strtoupper($details['type'] ?? '') === strtoupper($cardType) &&
                ($details['expirationDate'] ?? '') === $formattedExpiry
            ) {
                return (int) $token->getEntityId();
            }
        }

        return null;
    }

    private function buildExpirationTimestamp(string $month, string $year): string
    {
        $m    = (int) $month;
        $y    = (int) $year;
        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d',
            sprintf('%04d-%02d-01', $y, $m),
            new \DateTimeZone('UTC')
        );
        return $date->modify('last day of this month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    }

    // ── Private: Card detection & validation ──────────────────────────────────

    private function detectCardType(string $number): string
    {
        if (preg_match('/^4/', $number)) {
            return 'VISA';
        }
        if (preg_match('/^(5[1-5]|2(2[2-9][1-9]|[3-6]\d\d|7[01]\d|720))/', $number)) {
            return 'MASTERCARD';
        }
        if (preg_match('/^3[47]/', $number)) {
            return 'AMEX';
        }
        if (preg_match('/^6(011|4[4-9]|5)/', $number)) {
            return 'DISCOVER';
        }
        return 'OTHER';
    }

    /**
     * Standard Luhn algorithm for card number validation.
     */
    private function luhnCheck(string $number): bool
    {
        $sum  = 0;
        $flip = false;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];
            if ($flip) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $flip = !$flip;
        }
        return $sum % 10 === 0;
    }

    private function error(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
