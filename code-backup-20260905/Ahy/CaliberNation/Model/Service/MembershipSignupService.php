<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\MembershipFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class MembershipSignupService
{
    private const WELCOME_TEMPLATE_ID = 'caliber_nation_welcome';

    private const TIER_LABELS = [
        MembershipInterface::TIER_ANNUAL => 'Caliber Annual Membership',
    ];

    public function __construct(
        private CustomerService               $customerService,
        private MembershipRepositoryInterface $membershipRepository,
        private MembershipFactory             $membershipFactory,
        private TransportBuilder              $transportBuilder,
        private StateInterface                $inlineTranslation,
        private StoreManagerInterface         $storeManager,
        private UrlInterface                  $urlBuilder,
        private LoggerInterface               $logger,
        private Config                        $config,
        private ActivateMembership            $activateMembership
    ) {}

    /**
     * Step 1 — validate form, create Magento account for new users.
     * No membership record is created here; that happens in createMembership() after code verification.
     *
     * @param array{
     *     first_name: string,
     *     last_name:  string,
     *     email:      string,
     *     password:   string,
     *     tier:       string
     * } $data
     * @param int|null $loggedInCustomerId  Pass when the request comes from a logged-in session
     */
    public function initiate(array $data, ?int $loggedInCustomerId = null): array
    {
        $email = strtolower(trim($data['email'] ?? ''));
        $tier  = $data['tier'] ?? '';

        // --- Basic validation ---
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Please enter a valid email address.');
        }

        if (!\in_array($tier, MembershipInterface::VALID_TIERS, true)) {
            return $this->error('Please select a valid membership plan.');
        }

        if (empty($data['first_name']) || empty($data['last_name'])) {
            return $this->error('First name and last name are required.');
        }

        if (!$loggedInCustomerId && empty($data['password'])) {
            return $this->error('Password is required.');
        }

        // --- Already an active member? ---
        if ($this->customerService->isActiveMember($email)) {
            return [
                'success'   => false,
                'status'    => 'already_member',
                'message'   => 'This email already has an active Caliber Nation membership.',
                'login_url' => $this->buildLoginUrl(),
            ];
        }

        // --- Email exists but no membership → ask them to log in ---
        if (!$loggedInCustomerId && $this->customerService->emailExists($email)) {
            return [
                'success'   => false,
                'status'    => 'existing_user',
                'message'   => 'An account with this email already exists. Please log in to continue.',
                'login_url' => $this->buildLoginUrl(),
            ];
        }

        // --- New user — create the Magento account ---
        if (!$loggedInCustomerId) {
            try {
                $this->customerService->createAccount(
                    trim($data['first_name']),
                    trim($data['last_name']),
                    $email,
                    $data['password']
                );
            } catch (LocalizedException $e) {
                return $this->error($e->getMessage());
            } catch (\Exception) {
                return $this->error('Could not create account. Please try again.');
            }
        }

        return [
            'success' => true,
            'status'  => 'account_ready',
            'message' => 'Account ready. Please complete the verification step.',
        ];
    }

    /**
     * Proxy so Magewire components can resolve a customer ID by email
     * without needing a direct CustomerService dependency.
     */
    public function getCustomerIdByEmail(string $email): ?int
    {
        return $this->customerService->getCustomerIdByEmail(strtolower(trim($email)));
    }

    /**
     * Step 2 — create the membership record and send welcome email.
     * Called only after payment has been confirmed.
     *
     * @param int|null $paymentTokenId  vault_payment_token.entity_id — stored for auto-renewal
     */
    public function createMembership(
        string $email,
        string $tier,
        string $firstName = '',
        ?int   $paymentTokenId = null
    ): array {
        $email = strtolower(trim($email));

        if (!\in_array($tier, MembershipInterface::VALID_TIERS, true)) {
            return $this->error('Invalid membership plan.');
        }

        // --- Resolve customer ---
        $customerId = $this->customerService->getCustomerIdByEmail($email);
        if (!$customerId) {
            return $this->error('Customer account not found. Please start over.');
        }

        // --- Guard: no duplicate active memberships ---
        if ($this->customerService->isActiveMember($email)) {
            return [
                'success' => false,
                'status'  => 'already_member',
                'message' => 'You already have an active Caliber Nation membership.',
            ];
        }

        // --- Delegate to the single activation service (row + group + token + email + log) ---
        try {
            $this->activateMembership->activate($customerId, $tier, $paymentTokenId, null);
        } catch (\Exception) {
            return $this->error('Could not create membership. Please try again.');
        }

        return [
            'success' => true,
            'status'  => 'membership_created',
            'message' => 'Welcome to Caliber Nation! Your membership is now active.',
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function sendWelcomeEmail(
        string $email,
        string $firstName,
        string $tier,
        string $renewalDate
    ): void {
        $store   = $this->storeManager->getStore();
        $storeId = (int) $store->getId();

        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::WELCOME_TEMPLATE_ID)
                ->setTemplateOptions([
                    'area'  => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars([
                    'first_name'   => $firstName ?: 'Member',
                    'tier_label'   => self::TIER_LABELS[$tier] ?? ucfirst($tier) . ' Membership',
                    'renewal_date' => date('F j, Y', strtotime($renewalDate)),
                    'store_name'   => $store->getName(),
                    'store_url'    => $this->urlBuilder->getBaseUrl(),
                    'account_url'  => $this->urlBuilder->getUrl('customer/account'),
                ])
                ->setFromByScope($this->getEmailSender($storeId), $storeId)
                ->addTo($email)
                ->getTransport();

            $transport->sendMessage();
        } catch (\Exception) {
            // Non-fatal — membership exists; email can be retried
        } finally {
            $this->inlineTranslation->resume();
        }
    }

    private function buildLoginUrl(): string
    {
        $redirectBack = base64_encode(
            $this->urlBuilder->getUrl('caliber-nation') . '#membership-signup'
        );
        return $this->urlBuilder->getUrl('customer/account/login', [
            '_query' => ['referer' => $redirectBack],
        ]);
    }

    private function getEmailSender(int $storeId): string
    {
        return $this->config->getEmailSender((string) $storeId) ?: 'general';
    }

    private function error(string $message): array
    {
        return ['success' => false, 'status' => 'error', 'message' => $message];
    }
}
