<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Magewire;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\CustomerService;
use Ahy\CaliberNation\Model\Service\ExpiredMemberLocator;
use Ahy\CaliberNation\Model\Service\MembershipSignupService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;

/**
 * Account-first membership signup for the /caliber-nation landing page.
 *
 * Collects account details AND a billing address (no card fields). On submit it:
 *   1. creates the Magento account (guests) or uses the session (logged-in),
 *   2. logs the new customer in,
 *   3. saves a default billing address (required to place the order — the custom
 *      Hyva checkout does not collect billing for virtual carts),
 *   4. adds the membership virtual product to the cart,
 *   5. redirects to checkout, where payment is collected and the order placed.
 *
 * Payment is NEVER collected here.
 */
class MembershipSignup extends Component
{
    // ── Account fields ──────────────────────────────────────────────────────────
    public string $firstName       = '';
    public string $lastName        = '';
    public string $email           = '';
    public string $password        = '';
    public string $confirmPassword = '';
    public string $membershipPrice = '$99.99';

    // ── Billing address fields ──────────────────────────────────────────────────
    public string $street    = '';
    public string $city      = '';
    public string $regionId  = '';
    public string $postcode  = '';
    public string $telephone = '';

    // ── Session state ───────────────────────────────────────────────────────────
    public bool $programEnabled  = true;
    public bool $isLoggedIn      = false;
    public bool $isAlreadyMember = false;
    public bool $needsAddress    = true;
    public bool $isWinback       = false;
    /** Logged-in customer who has a PAST (non-active) membership → "welcome back / renew" copy. */
    public bool $isReturning     = false;

    // ── Feedback ──────────────────────────────────────────────────────────────--
    public string $errorMessage = '';
    public string $loginUrl     = '';

    public int $minPasswordLength = 8;
    public int $minCharacterSets  = 3;

    /**
     * Raise Magewire's global loader overlay (Magewirephp_Magewire::html/loader) while
     * join() runs — it creates the account, logs the user in, saves the billing address,
     * adds the membership to the cart and redirects to checkout, so the page must be
     * blocked rather than looking idle. Keyed by method so nothing else flashes it.
     *
     * @var bool|array
     */
    protected $loader = ['join' => true];

    private const XML_PATH_MIN_PW_LEN    = 'customer/password/minimum_password_length';
    private const XML_PATH_MIN_CHAR_SETS = 'customer/password/required_character_classes_number';

    public function __construct(
        private readonly MembershipSignupService       $signupService,
        private readonly CustomerService               $customerService,
        private readonly CustomerSession               $customerSession,
        private readonly Config                        $config,
        private readonly ScopeConfigInterface          $scopeConfig,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly ProductRepositoryInterface    $productRepository,
        private readonly Cart                          $cart,
        private readonly RegionCollectionFactory       $regionCollectionFactory,
        private readonly ExpiredMemberLocator          $expiredMemberLocator,
        private readonly LoggerInterface               $logger
    ) {}

    public function mount(): void
    {
        $this->programEnabled = $this->config->isEnabled();
        if (!$this->programEnabled) {
            return; // program off → template shows an "unavailable" notice, no form
        }

        $this->isLoggedIn = $this->customerSession->isLoggedIn();

        $this->minPasswordLength = \max(1, (int) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_PW_LEN,
            ScopeInterface::SCOPE_STORE
        ));
        $this->minCharacterSets = \max(1, (int) $this->scopeConfig->getValue(
            self::XML_PATH_MIN_CHAR_SETS,
            ScopeInterface::SCOPE_STORE
        ));

        $this->membershipPrice = $this->config->getFormattedMembershipPrice();

        if ($this->isLoggedIn) {
            $customerId      = (int) $this->customerSession->getCustomerId();
            $customer        = $this->customerSession->getCustomer();
            $this->firstName = $customer->getFirstname() ?? '';
            $this->lastName  = $customer->getLastname() ?? '';
            $this->email     = $customer->getEmail() ?? '';

            // Only collect an address if the customer doesn't already have a default billing one.
            $this->needsAddress = !$this->customerService->hasDefaultBilling($customerId);

            try {
                $membership = $this->membershipRepository->getByCustomerId($customerId);
                $this->isAlreadyMember =
                    $membership->getStatus() === MembershipInterface::STATUS_ACTIVE;
                // A membership record exists but isn't active (expired / cancelled /
                // renewal_pending) → the shopper is RENEWING, not creating an account.
                $this->isReturning = !$this->isAlreadyMember;
            } catch (NoSuchEntityException) {
                $this->isAlreadyMember = false;
                $this->isReturning     = false;
            }

            // Lapsed member eligible for the Day-31 win-back discount → show the discounted price.
            if (!$this->isAlreadyMember && $this->expiredMemberLocator->isWinbackEligible($customerId)) {
                $this->isWinback       = true;
                $this->membershipPrice = '$' . number_format($this->config->getWinbackPrice(), 2);
            }
        }
    }

    /** US states for the region dropdown: [region_id => name]. */
    public function getUsRegions(): array
    {
        $out = [];
        $collection = $this->regionCollectionFactory->create()->addCountryFilter('US');
        foreach ($collection as $region) {
            $out[(int) $region->getId()] = $region->getName();
        }
        asort($out);
        return $out;
    }

    /**
     * Ensure an account (+login) + default billing address, add the membership to
     * the cart, and send the user to checkout.
     */
    public function join(): void
    {
        $this->errorMessage = '';
        $this->loginUrl     = '';

        if (!$this->config->isEnabled()) {
            $this->errorMessage = 'Caliber Nation membership is currently unavailable.';
            return;
        }

        if ($this->isAlreadyMember) {
            $this->redirect('caliber-nation/account');
            return;
        }

        // ── Validate ──────────────────────────────────────────────────────────
        if (!$this->isLoggedIn && !$this->validateNewAccount()) {
            return;
        }
        if ($this->needsAddress && !$this->validateAddress()) {
            return;
        }

        // ── Ensure account + login (guests) ─────────────────────────────────────
        $customerId = $this->isLoggedIn ? (int) $this->customerSession->getCustomerId() : null;

        if (!$this->isLoggedIn) {
            $result = $this->signupService->initiate(
                [
                    'first_name' => trim($this->firstName),
                    'last_name'  => trim($this->lastName),
                    'email'      => trim($this->email),
                    'password'   => $this->password,
                    'tier'       => MembershipInterface::TIER_ANNUAL,
                ],
                null
            );

            if (!$result['success']) {
                // Surface a "Log in here" link whenever the service offers one
                // (existing account OR an email that's already an active member).
                $this->loginUrl     = $result['login_url'] ?? '';
                $this->errorMessage = $result['message'];
                return;
            }

            $this->password        = '';
            $this->confirmPassword = '';

            $customerId = $this->signupService->getCustomerIdByEmail(trim($this->email));
            if ($customerId) {
                try {
                    $this->customerSession->loginById($customerId);
                    $this->isLoggedIn = true;
                } catch (\Exception $e) {
                    $this->logger->error('[CaliberNation] auto-login after signup failed: ' . $e->getMessage());
                }
            }
        }

        if (!$customerId) {
            $this->errorMessage = 'Your session has expired. Please start over.';
            return;
        }

        // ── Save default billing address (required for order placement) ─────────
        if ($this->needsAddress) {
            try {
                $this->customerService->createDefaultBillingAddress(
                    $customerId,
                    trim($this->firstName),
                    trim($this->lastName),
                    trim($this->street),
                    trim($this->city),
                    (int) $this->regionId,
                    trim($this->postcode),
                    trim($this->telephone)
                );
            } catch (\Exception $e) {
                $this->logger->error('[CaliberNation] save billing address failed: ' . $e->getMessage());
                $this->errorMessage = 'Could not save your address. Please check the fields and try again.';
                return;
            }
        }

        // ── Add to cart + checkout ──────────────────────────────────────────────
        try {
            $this->addMembershipToCart();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // e.g. the active-member guard — surface its specific message.
            $this->errorMessage = $e->getMessage();
            return;
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] add membership to cart failed: ' . $e->getMessage());
            $this->errorMessage = 'Could not start checkout. Please try again.';
            return;
        }

        $this->redirect('checkout');
    }

    // ── Private helpers ───────────────────────────────────────────────────────--

    private function validateNewAccount(): bool
    {
        if (\strlen($this->password) < $this->minPasswordLength) {
            $this->errorMessage = "Password must be at least {$this->minPasswordLength} characters.";
            return false;
        }

        $sets = 0;
        if (\preg_match('/[a-z]/', $this->password)) $sets++;
        if (\preg_match('/[A-Z]/', $this->password)) $sets++;
        if (\preg_match('/[0-9]/', $this->password)) $sets++;
        if (\preg_match('/[^a-zA-Z0-9]/', $this->password)) $sets++;

        if ($sets < $this->minCharacterSets) {
            $this->errorMessage = "Password must contain at least {$this->minCharacterSets} of: "
                . 'lowercase letters, uppercase letters, digits, special characters.';
            return false;
        }

        if ($this->password !== $this->confirmPassword) {
            $this->errorMessage = 'Passwords do not match.';
            return false;
        }

        return true;
    }

    private function validateAddress(): bool
    {
        if (empty(trim($this->firstName)) || empty(trim($this->lastName))) {
            $this->errorMessage = 'First name and last name are required.';
            return false;
        }
        if (\preg_match('/^\d/', trim($this->firstName)) || \preg_match('/^\d/', trim($this->lastName))) {
            $this->errorMessage = 'First and last name cannot start with a number.';
            return false;
        }
        if (empty(trim($this->street)) || empty(trim($this->city))
            || empty(trim($this->postcode)) || empty(trim($this->telephone))) {
            $this->errorMessage = 'Please complete your billing address (street, city, ZIP, phone).';
            return false;
        }
        if ((int) $this->regionId <= 0) {
            $this->errorMessage = 'Please select your state.';
            return false;
        }
        return true;
    }

    private function addMembershipToCart(): void
    {
        $sku     = $this->config->getMembershipSku();
        $product = $this->productRepository->get($sku);

        foreach ($this->cart->getQuote()->getAllItems() as $item) {
            if ($item->getSku() === $sku) {
                return;
            }
        }

        $this->cart->addProduct($product, ['qty' => 1]);
        $this->cart->save();
    }
}
