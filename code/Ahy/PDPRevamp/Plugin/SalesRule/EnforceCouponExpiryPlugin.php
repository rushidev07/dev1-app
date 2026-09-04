<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\SalesRule;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateExitPopupSalesRule;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Utility;
use Psr\Log\LoggerInterface;

/**
 * Enforces salesrule_coupon.expiration_date, which Magento 2.4.5 does not.
 *
 * The exit popup shows a countdown and stamps a matching per-coupon deadline via
 * ExitPopupCouponService::applyExpiry(). That deadline was decorative: Magento
 * writes the column and exposes a getter, but nothing reads it back.
 *
 * Verified rather than assumed. In module-sales-rule, "expiration_date" appears
 * in exactly three places - db_schema.xml, the KEY_EXPIRATION_DATE constant, and
 * db_schema_whitelist.json - and no caller anywhere in vendor/ reads
 * Coupon::getExpirationDate() (every hit is unrelated credit-card expiry in Vault
 * and Braintree). Coupon validation runs through
 * SalesRule\Model\ResourceModel\Rule\Collection::setValidationFilter(), whose
 * coupon join (joinCouponTable) constrains only rule_id, coupon_type and code;
 * the date filtering that does exist - addWebsiteGroupDateFilter - checks the
 * *rule's* from_date/to_date, not the coupon's. A coupon backdated to 2020 was
 * confirmed to still match that query.
 *
 * Utility::canProcessRule is the hook because it is the per-rule gate consulted
 * during totals collection, and it already performs the directly analogous
 * checks - usage_limit and usage_per_customer - on the same loaded coupon, using
 * the same setIsValidForAddress(false) + return false idiom. Filtering the
 * collection instead would have to reach into a private method.
 *
 * An "after" plugin, deliberately: this can only ever veto a true verdict, never
 * turn a false one true. If core has already rejected the rule for its own
 * reasons, that decision stands untouched.
 *
 * Scoped to the exit-popup rule by name. The underlying bug is generic - every
 * auto-generated coupon on this store has an unenforced expiry - but silently
 * changing the behaviour of unrelated campaigns is not this module's call. See
 * the class note in getExpiryFor() if that scope is ever widened.
 */
class EnforceCouponExpiryPlugin
{
    private CouponFactory $couponFactory;
    private LoggerInterface $logger;

    /**
     * Rule ids already matched to the exit-popup rule, keyed by id.
     *
     * canProcessRule is called for every rule on every totals collection, and a
     * cart recollects totals several times per request. Without this, each call
     * would re-read the rule name.
     *
     * @var array<int, bool>
     */
    private array $isExitPopupRule = [];

    public function __construct(CouponFactory $couponFactory, LoggerInterface $logger)
    {
        $this->couponFactory = $couponFactory;
        $this->logger = $logger;
    }

    /**
     * @param Utility $subject
     * @param bool $result
     * @param Rule $rule
     * @param Address $address
     */
    public function afterCanProcessRule(
        Utility $subject,
        $result,
        $rule,
        $address
    ): bool {
        // Core already said no - leave that alone.
        if (!$result) {
            return false;
        }

        try {
            if (!$this->appliesTo($rule)) {
                return true;
            }

            $quote = $address->getQuote();
            if ($quote === null) {
                return true;
            }

            $couponCode = (string) $quote->getCouponCode();
            if ($couponCode === '') {
                // The rule can also apply without a coupon; nothing to expire.
                return true;
            }

            if (!$this->isExpired($couponCode)) {
                return true;
            }

            // Mirrors how core rejects a rule here, so the verdict is cached
            // against this address for the rest of the collection pass rather
            // than re-derived per item.
            $rule->setIsValidForAddress($address, false);

            return false;
        } catch (\Throwable $exception) {
            // A failure here must not break totals collection. Erring towards
            // the core verdict means an expired code might slip through, which
            // is the pre-existing behaviour rather than a new breakage.
            $this->logger->error(
                '[PDPRevamp] coupon expiry check failed: ' . $exception->getMessage()
            );

            return (bool) $result;
        }
    }

    /**
     * Whether this is the exit-popup rule.
     *
     * Matched on name rather than a stored id because the id differs per
     * environment - the rule is created by a data patch, not fixture data - and
     * the name is the patch's own constant.
     */
    private function appliesTo($rule): bool
    {
        if (!$rule instanceof Rule) {
            return false;
        }

        $ruleId = (int) $rule->getId();
        if ($ruleId < 1) {
            return false;
        }

        if (!array_key_exists($ruleId, $this->isExitPopupRule)) {
            $this->isExitPopupRule[$ruleId] =
                (string) $rule->getName() === CreateExitPopupSalesRule::RULE_NAME;
        }

        return $this->isExitPopupRule[$ruleId];
    }

    /**
     * Whether this coupon is past its expiry.
     *
     * Compared in UTC because ExitPopupCouponService::applyExpiry() stamps the
     * value with an explicit UTC DateTime, while the database session is on local
     * time - MySQL NOW() and the stored value are not in the same zone. Comparing
     * in the wrong one would be wrong by the local offset in whichever direction:
     * rejecting codes that are still valid, or honouring ones that are not.
     *
     * A null or empty expiry means open-ended (no timer configured), not expired.
     */
    private function isExpired(string $couponCode): bool
    {
        $coupon = $this->couponFactory->create();
        $coupon->loadByCode($couponCode);

        if (!$coupon->getId()) {
            // Not a coupon we know; core handles unknown codes.
            return false;
        }

        $expiresAt = $coupon->getExpirationDate();
        if (!$expiresAt) {
            return false;
        }

        $utc = new \DateTimeZone('UTC');
        $expiry = new \DateTime((string) $expiresAt, $utc);
        $now = new \DateTime('now', $utc);

        return $expiry < $now;
    }
}
