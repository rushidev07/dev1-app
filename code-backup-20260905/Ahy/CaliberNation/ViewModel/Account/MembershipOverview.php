<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel\Account;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Vault\Model\ResourceModel\PaymentToken\CollectionFactory as TokenCollectionFactory;

class MembershipOverview implements ArgumentInterface
{
    private ?MembershipInterface $membership = null;
    private bool $loaded = false;
    private ?\Magento\Vault\Model\PaymentToken $boundToken = null;
    private bool $boundTokenLoaded = false;

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly TokenCollectionFactory $tokenCollectionFactory,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {}

    /** Whether the whole program is switched on (master admin flag). */
    public function isProgramEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    // ── Membership record ─────────────────────────────────────────────────────

    public function getMembership(): ?MembershipInterface
    {
        if ($this->loaded) {
            return $this->membership;
        }

        $this->loaded = true;

        // Program off → behave as if the customer has no membership (hides the panel).
        if (!$this->config->isEnabled()) {
            return null;
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        if (!$customerId) {
            return null;
        }

        try {
            $this->membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            $this->membership = null;
        }

        return $this->membership;
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function hasMembership(): bool
    {
        return $this->getMembership() !== null;
    }

    public function isActive(): bool
    {
        return $this->getMembership()?->getStatus() === MembershipInterface::STATUS_ACTIVE;
    }

    /**
     * Lapsed OR mid-retry — both share the same "you have no benefits right now,
     * here's how to fix it" panel layout. The COPY inside differs: use
     * isRenewalPending() to tell them apart, because a pending member has NOT
     * lapsed (the renewal charge is still being retried) and telling them their
     * membership "expired" overstates the situation.
     */
    public function isExpired(): bool
    {
        return \in_array($this->getMembership()?->getStatus(), [
            MembershipInterface::STATUS_EXPIRED,
            MembershipInterface::STATUS_RENEWAL_PENDING,
        ], true);
    }

    /** Auto-renewal charge failed and is still being retried (no benefits meanwhile). */
    public function isRenewalPending(): bool
    {
        return $this->getMembership()?->getStatus() === MembershipInterface::STATUS_RENEWAL_PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->getMembership()?->getStatus() === MembershipInterface::STATUS_CANCELLED;
    }

    /**
     * Cancelled BUT still inside the paid-through window, i.e. benefits are STILL
     * ACTIVE right now. Mirrors MemberAccess::isActiveMember()'s cancelled branch,
     * so the UI never tells a member they've lost access the pricing engine is
     * still granting them.
     */
    public function isWithinPaidThrough(): bool
    {
        if (!$this->isCancelled()) {
            return false;
        }
        $renewal = $this->getMembership()?->getRenewalDate();

        return $renewal && strtotime((string) $renewal) > time();
    }

    // ── Display labels ────────────────────────────────────────────────────────

    public function getStatusLabel(): string
    {
        return match ($this->getMembership()?->getStatus()) {
            MembershipInterface::STATUS_ACTIVE           => 'Active',
            MembershipInterface::STATUS_RENEWAL_PENDING  => 'Renewal Pending',
            MembershipInterface::STATUS_EXPIRED          => 'Expired',
            MembershipInterface::STATUS_CANCELLED        => 'Cancelled',
            default                                      => '—',
        };
    }

    public function getTierLabel(): string
    {
        return match ($this->getMembership()?->getTier()) {
            MembershipInterface::TIER_ANNUAL => 'Annual',
            default                          => '—',
        };
    }

    // ── Formatted dates ───────────────────────────────────────────────────────

    public function getFormattedStartDate(): string
    {
        $date = $this->getMembership()?->getStartDate();
        return $date ? date('F j, Y', strtotime($date)) : '—';
    }

    public function getFormattedRenewalDate(): string
    {
        $date = $this->getMembership()?->getRenewalDate();
        return $date ? date('F j, Y', strtotime($date)) : '—';
    }

    // ── Payment method ────────────────────────────────────────────────────────

    /**
     * Returns a masked card summary e.g. "VISA •••• 4242".
     * Falls back to "Not set" when no *usable* vault token is linked (a soft-deleted
     * or expired bound card counts as not set — it can't renew).
     */
    public function getPaymentMethodSummary(): string
    {
        $token = $this->getBoundUsableToken();
        if (!$token) {
            return 'Not set';
        }

        $details = json_decode($token->getTokenDetails() ?? '{}', true);
        $type    = strtoupper($details['type'] ?? '');
        $masked  = $details['maskedCC'] ?? '****';

        return trim("{$type} •••• {$masked}");
    }

    /**
     * Returns the card expiry date e.g. "07/2027", or empty string when unavailable.
     */
    public function getPaymentMethodExpiry(): string
    {
        $token = $this->getBoundUsableToken();
        if (!$token) {
            return '';
        }

        $details = json_decode($token->getTokenDetails() ?? '{}', true);
        return $details['expirationDate'] ?? '';
    }

    /**
     * The membership's bound renewal token, but only if it's still usable
     * (active + visible). A deleted/hidden token resolves to null so the UI never
     * shows a card that can no longer be charged.
     */
    private function getBoundUsableToken(): ?\Magento\Vault\Model\PaymentToken
    {
        $token = $this->getBoundToken();
        if (!$token) {
            return null;
        }

        // An expired card cannot be charged, so it is not "usable" for renewal even
        // though it is still active + visible in the vault.
        return $this->isTokenExpired($token) ? null : $token;
    }

    /**
     * The membership's bound token regardless of expiry — needed so the UI can say
     * "your card expired" rather than silently showing "Not set", which reads as though
     * the member never added one.
     */
    private function getBoundToken(): ?\Magento\Vault\Model\PaymentToken
    {
        if ($this->boundTokenLoaded) {
            return $this->boundToken;
        }
        $this->boundTokenLoaded = true;

        $tokenId = $this->getMembership()?->getPaymentTokenId();
        if (!$tokenId) {
            return null;
        }

        $collection = $this->tokenCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['eq' => (int) $tokenId]);
        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('is_visible', 1);
        $collection->setPageSize(1);

        /** @var \Magento\Vault\Model\PaymentToken $token */
        $token = $collection->getFirstItem();

        return $this->boundToken = ($token && $token->getEntityId()) ? $token : null;
    }

    /**
     * Whether the bound card has passed its expiry.
     *
     * Checks BOTH sources because they can disagree: vault_payment_token.expires_at is
     * written by the payment integration, while details.expirationDate ("MM/YYYY") is
     * captured from the card at save time. Observed live: one token with expires_at
     * 2025-01 but details 07/2032, and another showing details 06/2026 with a future
     * expires_at. Either being in the past means the card cannot be charged, so treat
     * the card as expired if EITHER says so — under-warning risks a silent failed
     * renewal, which is the worse outcome.
     */
    private function isTokenExpired(\Magento\Vault\Model\PaymentToken $token): bool
    {
        $expiresAt = $token->getExpiresAt();
        if ($expiresAt && strtotime((string) $expiresAt) <= time()) {
            return true;
        }

        $details = json_decode($token->getTokenDetails() ?? '{}', true);
        $carded  = (string) ($details['expirationDate'] ?? '');

        // "MM/YYYY" — a card is valid through the END of its expiry month.
        if (preg_match('~^(\d{1,2})/(\d{4})$~', $carded, $m)) {
            $month = (int) $m[1];
            $year  = (int) $m[2];
            if ($month >= 1 && $month <= 12) {
                $endOfMonth = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month) . ' +1 month');

                return $endOfMonth !== false && $endOfMonth <= time();
            }
        }

        // Neither source proves expiry → treat as usable rather than wrongly warning.
        return false;
    }

    /**
     * True when the card bound for renewal has passed its expiry date. Drives the
     * warning on the account panel: auto-renew cannot succeed against this card.
     */
    public function isRenewalCardExpired(): bool
    {
        $token = $this->getBoundToken();

        return $token !== null && $this->isTokenExpired($token);
    }

    /** Masked summary of the EXPIRED bound card, e.g. "VISA •••• 1111". */
    public function getExpiredCardSummary(): string
    {
        $token = $this->getBoundToken();
        if (!$token || !$this->isTokenExpired($token)) {
            return '';
        }

        $details = json_decode($token->getTokenDetails() ?? '{}', true);
        $type    = strtoupper($details['type'] ?? '');
        $masked  = $details['maskedCC'] ?? '****';

        return trim("{$type} •••• {$masked}");
    }

    /**
     * The expiry date to SHOW in the warning — whichever source is actually in the past.
     *
     * The two sources can disagree (see isTokenExpired), so displaying the wrong one
     * produces a contradiction like "Card expired 06/2030". Prefer the date that has
     * genuinely passed; when both have, show the earlier.
     */
    public function getExpiredCardExpiry(): string
    {
        $token = $this->getBoundToken();
        if (!$token) {
            return '';
        }

        $candidates = [];

        $expiresAt = $token->getExpiresAt();
        if ($expiresAt) {
            $ts = strtotime((string) $expiresAt);
            if ($ts && $ts <= time()) {
                $candidates[] = $ts;
            }
        }

        $details = json_decode($token->getTokenDetails() ?? '{}', true);
        $carded  = (string) ($details['expirationDate'] ?? '');
        if (preg_match('~^(\d{1,2})/(\d{4})$~', $carded, $m)) {
            $endOfMonth = strtotime(sprintf('%04d-%02d-01 00:00:00', (int) $m[2], (int) $m[1]) . ' +1 month');
            if ($endOfMonth && $endOfMonth <= time()) {
                // Report the month printed on the card, not the exclusive end boundary.
                $candidates[] = strtotime(sprintf('%04d-%02d-01', (int) $m[2], (int) $m[1]));
            }
        }

        if ($candidates) {
            return date('m/Y', min($candidates));
        }

        // Not expired by either source; fall back to whatever the card says.
        return $carded;
    }

    // ── Customer ─────────────────────────────────────────────────────────────

    /** Returns the customer's full name in UPPERCASE for use on the membership card. */
    public function getCustomerName(): string
    {
        $customer = $this->customerSession->getCustomer();
        $name     = trim($customer->getFirstname() . ' ' . $customer->getLastname());
        return $name ? strtoupper($name) : 'CALIBER MEMBER';
    }

    // ── Savings ───────────────────────────────────────────────────────────────

    /** Raw cumulative lifetime savings (never reset — P3 savings engine). */
    public function getTotalSavingsAmount(): float
    {
        return (float) $this->getMembership()?->getLifetimeSavings();
    }

    /** Formatted cumulative lifetime member savings, e.g. "$124.50". */
    public function getTotalSavings(): string
    {
        return '$' . number_format($this->getTotalSavingsAmount(), 2);
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    /**
     * Landing page flagged to scroll to the signup/renew form. Used by the Renew CTAs
     * so the member lands on the form rather than the top of the marketing page.
     */
    public function getRenewUrl(): string
    {
        return $this->urlBuilder->getUrl('caliber-nation', [
            '_query' => [Config::SIGNUP_SCROLL_PARAM => Config::SIGNUP_SCROLL_VALUE],
        ]);
    }

    // ── Auto-renew ────────────────────────────────────────────────────────────

    public function isAutoRenewEnabled(): bool
    {
        return (bool) $this->getMembership()?->getAutoRenew();
    }

}
