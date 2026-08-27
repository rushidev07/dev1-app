<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader for all Caliber Nation system configuration values.
 *
 * Inject this class wherever config values are needed — never call
 * ScopeConfigInterface directly with raw XML paths outside this class.
 */
class Config
{
    /**
     * Fragment identifying the signup/renew form section on the landing page
     * (id="membership-signup" in membership-signup.phtml). Append it to landing-page
     * URLs so a Join/Renew CTA drops the user straight at the form instead of the
     * top of a tall marketing page.
     */
    public const SIGNUP_ANCHOR = '#membership-signup';

    /**
     * Query flag telling the landing page to smooth-scroll to the signup form.
     * A bare #fragment can't be animated: browsers jump to it instantly during
     * navigation, leaving nothing to scroll. Landing at the top with this flag set
     * lets the page own the whole scroll.
     */
    public const SIGNUP_SCROLL_PARAM = 'scrollTo';
    public const SIGNUP_SCROLL_VALUE = 'signup';

    // ── XML paths ─────────────────────────────────────────────────────────────
    private const XML_ENABLED            = 'caliber_nation/general/enabled';
    private const XML_MEMBERSHIP_PRICE   = 'caliber_nation/membership/price';
    private const XML_TRIAL_ENABLED      = 'caliber_nation/membership/trial_enabled';
    private const XML_TRIAL_MONTHS       = 'caliber_nation/membership/trial_months';
    private const XML_MAX_RENEWAL_TRIES  = 'caliber_nation/membership/max_renewal_attempts';
    private const XML_EMAIL_SENDER       = 'caliber_nation/email/sender';
    private const XML_MEMBER_GROUP_ID    = 'caliber_nation/general/member_group_id';
    private const XML_MEMBERSHIP_SKU     = 'caliber_nation/general/membership_sku';
    private const XML_PRICING_ENABLED    = 'caliber_nation/pricing/enabled';
    private const XML_PRICING_BASE_TYPE  = 'caliber_nation/pricing/base_discount_type';
    private const XML_PRICING_BASE_VALUE = 'caliber_nation/pricing/base_discount_value';
    private const XML_PRICING_CAP_TYPE   = 'caliber_nation/pricing/cap_type';
    private const XML_PRICING_CAP_VALUE  = 'caliber_nation/pricing/cap_value';
    private const XML_WINBACK_ENABLED    = 'caliber_nation/winback/enabled';
    private const XML_WINBACK_DAYS       = 'caliber_nation/winback/days_threshold';
    private const XML_WINBACK_TYPE       = 'caliber_nation/winback/discount_type';
    private const XML_WINBACK_VALUE      = 'caliber_nation/winback/discount_value';
    private const XML_MSG_RENEWAL_HEADING = 'caliber_nation/messaging/renewal_prompt_heading';
    private const XML_MSG_RENEWAL_BODY    = 'caliber_nation/messaging/renewal_prompt_body';
    private const XML_MSG_SAVINGS         = 'caliber_nation/messaging/savings_message';
    private const XML_MSG_AVG_INTRO       = 'caliber_nation/messaging/average_savings_intro';
    private const XML_MSG_AVG_STATIC      = 'caliber_nation/messaging/average_savings_static';
    private const XML_MSG_EARLYBIRD       = 'caliber_nation/messaging/earlybird_message';
    private const XML_STAT_AVG_SAVINGS    = 'caliber_nation/landing/stat_avg_savings';
    private const XML_STAT_MONTHLY_COST   = 'caliber_nation/landing/stat_monthly_cost';
    private const XML_STAT_MAX_DISCOUNT   = 'caliber_nation/landing/stat_max_discount';
    private const XML_CANCEL_SEND_EMAIL   = 'caliber_nation/cancellation/send_email';
    private const XML_CANCEL_CONFIRM_MSG  = 'caliber_nation/cancellation/confirmation_message';
    private const XML_EMAIL_EXPIRY        = 'caliber_nation/emails/expiry_enabled';
    private const XML_EMAIL_REMINDER      = 'caliber_nation/emails/renewal_reminder_enabled';
    private const XML_EMAIL_REMINDER_DAYS = 'caliber_nation/emails/renewal_reminder_days';
    private const XML_EMAIL_WINBACK              = 'caliber_nation/emails/winback_enabled';
    private const XML_EARLY_CANCEL_ENABLED       = 'caliber_nation/early_cancellation/enabled';
    private const XML_EARLY_CANCEL_WINDOW_DAYS   = 'caliber_nation/early_cancellation/refund_window_days';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    // ── General ───────────────────────────────────────────────────────────────

    /**
     * Master switch for the entire Caliber Nation program. When OFF, every
     * feature — activation, member pricing, savings tracking, restrictions,
     * lifecycle crons/emails, and all customer-facing UI — is disabled. This is
     * the single flag every entry point checks first.
     */
    public function isEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );
    }

    // ── Membership ────────────────────────────────────────────────────────────

    /**
     * Annual membership price as configured in the admin.
     */
    public function getMembershipPrice(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_MEMBERSHIP_PRICE,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );
    }

    /**
     * Formatted price string for display (e.g. "$99.99").
     */
    public function getFormattedMembershipPrice(?string $storeCode = null): string
    {
        return '$' . number_format($this->getMembershipPrice($storeCode), 2);
    }

    /**
     * Whether the bonus month feature is enabled.
     */
    public function isTrialEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_TRIAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );
    }

    /**
     * Number of bonus months granted at signup (only relevant when trial is enabled).
     */
    public function getTrialMonths(?string $storeCode = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_TRIAL_MONTHS,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );
    }

    /**
     * Total membership duration in months: 12 standard + bonus months if enabled.
     * Use this when setting the renewal_date on a new membership record.
     */
    public function getMembershipDurationMonths(?string $storeCode = null): int
    {
        $months = 12;

        if ($this->isTrialEnabled($storeCode)) {
            $months += $this->getTrialMonths($storeCode);
        }

        return $months;
    }

    /**
     * How many failed auto-renewal charges a membership tolerates before it is
     * marked Expired. The renewal cron retries once per day, so 3 = expires on the
     * third failed day. Falls back to 3 if unset/invalid so renewals can never
     * expire on the first failure by misconfiguration.
     */
    public function getMaxRenewalAttempts(?string $storeCode = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_MAX_RENEWAL_TRIES,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );

        return $value > 0 ? $value : 3;
    }

    // ── Email ─────────────────────────────────────────────────────────────────

    /**
     * Email sender identity (e.g. "general", "sales").
     */
    public function getEmailSender(?string $storeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_EMAIL_SENDER,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );
    }

    // ── Membership program (internal — set by setup patches) ────────────────────

    /**
     * Customer group id representing an active Caliber Nation member.
     * Created and stored by CreateCaliberMemberGroup data patch. 0 = not yet set.
     */
    public function getMemberGroupId(): int
    {
        return (int) $this->scopeConfig->getValue(self::XML_MEMBER_GROUP_ID);
    }

    /**
     * SKU of the membership virtual product used for order-based purchase.
     */
    public function getMembershipSku(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_MEMBERSHIP_SKU);
    }

    // ── Member pricing (additive engine — Phase 3) ─────────────────────────────

    /** Master switch for the member-pricing engine. */
    public function isPricingEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PRICING_ENABLED, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Membership base discount type: 'percent' or 'fixed' (amount off). */
    public function getBaseDiscountType(?string $storeCode = null): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PRICING_BASE_TYPE, ScopeInterface::SCOPE_STORE, $storeCode) ?: 'percent');
    }

    /** Membership base discount value (every active member, all products). 0 = none. */
    public function getBaseDiscountValue(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_PRICING_BASE_VALUE, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Global cap type: 'percent' (max % off) or 'fixed' (max amount off). */
    public function getCapType(?string $storeCode = null): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PRICING_CAP_TYPE, ScopeInterface::SCOPE_STORE, $storeCode) ?: 'percent');
    }

    /** Global cap value. 0 = no cap. Governs the member discount only (not coupons). */
    public function getCapValue(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_PRICING_CAP_VALUE, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    // ── Win-back (Day-31 discounted renewal for lapsed members) ─────────────────

    public function isWinbackEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_WINBACK_ENABLED, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Days a membership must be expired before the win-back discount applies. */
    public function getWinbackDaysThreshold(?string $storeCode = null): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_WINBACK_DAYS, ScopeInterface::SCOPE_STORE, $storeCode) ?: 30);
    }

    /** 'fixed' (a flat price) or 'percent' (percent off the standard price). */
    public function getWinbackDiscountType(?string $storeCode = null): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_WINBACK_TYPE, ScopeInterface::SCOPE_STORE, $storeCode) ?: 'percent');
    }

    public function getWinbackDiscountValue(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_WINBACK_VALUE, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /**
     * The discounted win-back price computed from the standard membership price.
     * 'fixed' → the configured value is the price; 'percent' → percent off.
     * Never returns below 0.
     */
    public function getWinbackPrice(?string $storeCode = null): float
    {
        $base  = $this->getMembershipPrice($storeCode);
        $value = $this->getWinbackDiscountValue($storeCode);

        if ($this->getWinbackDiscountType($storeCode) === 'fixed') {
            $price = $value;
        } else {
            $price = $base - ($base * $value / 100);
        }

        return max(0.0, round($price, 2));
    }

    // ── Messaging (Phase 4) ─────────────────────────────────────────────────────

    public function getRenewalPromptHeading(?string $storeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_MSG_RENEWAL_HEADING, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    public function getRenewalPromptBody(?string $storeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_MSG_RENEWAL_BODY, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    public function getSavingsMessage(?string $storeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_MSG_SAVINGS, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    public function getAverageSavingsIntro(?string $storeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_MSG_AVG_INTRO, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Fallback average-savings amount used when there isn't enough order data (0 = hide). */
    public function getAverageSavingsStatic(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_MSG_AVG_STATIC, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    public function getEarlybirdMessage(?string $storeCode = null): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_MSG_EARLYBIRD, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    // ── Landing-page stats band ─────────────────────────────────────────────────

    /** Raw "average savings per year" figure, e.g. 3500.0. */
    public function getStatAvgSavings(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_STAT_AVG_SAVINGS, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /**
     * Raw monthly-equivalent figure. When the admin leaves the field empty we derive
     * it from the annual price instead of showing a stale hard-coded number, so the
     * two can never contradict each other on the page.
     */
    public function getStatMonthlyCost(?string $storeCode = null): float
    {
        $configured = (string) $this->scopeConfig->getValue(
            self::XML_STAT_MONTHLY_COST,
            ScopeInterface::SCOPE_STORE,
            $storeCode
        );

        if (trim($configured) !== '') {
            return (float) $configured;
        }

        return round($this->getMembershipPrice($storeCode) / 12, 2);
    }

    /** Raw maximum-discount percentage, e.g. 90.0. */
    public function getStatMaxDiscount(?string $storeCode = null): float
    {
        return (float) $this->scopeConfig->getValue(self::XML_STAT_MAX_DISCOUNT, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    // ── Cancellation handling (Phase 4) ─────────────────────────────────────────

    public function isCancellationEmailEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_CANCEL_SEND_EMAIL, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** On-screen cancellation confirmation; falls back to a sensible default when blank. */
    public function getCancellationConfirmationMessage(?string $storeCode = null): string
    {
        $msg = (string) $this->scopeConfig->getValue(self::XML_CANCEL_CONFIRM_MSG, ScopeInterface::SCOPE_STORE, $storeCode);
        return $msg !== '' ? $msg : 'Your membership has been cancelled. You keep your benefits until your paid-through date.';
    }

    // ── Lifecycle emails (expiry / advance-notice / win-back) ───────────────────

    public function isExpiryEmailEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_EMAIL_EXPIRY, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    public function isRenewalReminderEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_EMAIL_REMINDER, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Days before the renewal date to send the advance-notice email (min 1). */
    public function getRenewalReminderDays(?string $storeCode = null): int
    {
        $days = (int) $this->scopeConfig->getValue(self::XML_EMAIL_REMINDER_DAYS, ScopeInterface::SCOPE_STORE, $storeCode);
        return $days > 0 ? $days : 7;
    }

    public function isWinbackEmailEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_EMAIL_WINBACK, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Whether early cancellation tracking is enabled. */
    public function isEarlyCancellationEnabled(?string $storeCode = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_EARLY_CANCEL_ENABLED, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /** Days from start date within which a cancellation is flagged for refund review. */
    public function getEarlyCancellationWindowDays(?string $storeCode = null): int
    {
        return (int) ($this->scopeConfig->getValue(self::XML_EARLY_CANCEL_WINDOW_DAYS, ScopeInterface::SCOPE_STORE, $storeCode) ?: 30);
    }
}
