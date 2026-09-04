<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateExitPopupSalesRule;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterfaceFactory;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates one single-use coupon code per exit-popup subscriber, tied to the
 * Cart Price Rule created by Setup\Patch\Data\CreateExitPopupSalesRule. Used
 * by Controller\Index\Subscribe so the "10% off" the popup promises is a real,
 * redeemable code rather than just popup copy.
 */
class ExitPopupCouponService
{
    private const XML_PATH_DISCOUNT_PERCENT = 'pdprevamp_exit_popup/general/discount_percent';
    /** Same setting the popup's countdown reads, so timer and coupon expiry stay in step. */
    private const XML_PATH_TIMER_HOURS = 'pdprevamp_exit_popup/general/timer_hours';

    /** Generated codes look like "A1B2C3D4" - long enough not to collide, short enough to type. */
    private const COUPON_LENGTH = 8;
    private const COUPON_FORMAT_ALPHANUMERIC = 'alphanum';

    private RuleFactory $ruleFactory;
    private RuleResource $ruleResource;
    private CouponManagementInterface $couponManagement;
    private CouponGenerationSpecInterfaceFactory $specFactory;
    private CouponFactory $couponFactory;
    private CouponResource $couponResource;
    private ResourceConnection $resourceConnection;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    public function __construct(
        RuleFactory $ruleFactory,
        RuleResource $ruleResource,
        CouponManagementInterface $couponManagement,
        CouponGenerationSpecInterfaceFactory $specFactory,
        CouponFactory $couponFactory,
        CouponResource $couponResource,
        ResourceConnection $resourceConnection,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->ruleFactory = $ruleFactory;
        $this->ruleResource = $ruleResource;
        $this->couponManagement = $couponManagement;
        $this->specFactory = $specFactory;
        $this->couponFactory = $couponFactory;
        $this->couponResource = $couponResource;
        $this->resourceConnection = $resourceConnection;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function getDiscountPercent(): int
    {
        $percent = (int) $this->scopeConfig->getValue(self::XML_PATH_DISCOUNT_PERCENT, ScopeInterface::SCOPE_STORE);
        return $percent > 0 ? $percent : 10;
    }

    /**
     * Null when the rule is missing (run setup:upgrade) or generation fails -
     * the caller should treat that as a real failure, not silently claim a
     * code was sent when one wasn't.
     */
    public function generateCode(): ?string
    {
        // Resolve the id with a direct query rather than $rule->load($name,
        // 'name'). AbstractModel::load() by a non-key field does not reliably
        // hydrate this model - it returns a null id even with the row present,
        // which made generateCode() bail here and return no code at all. The
        // same problem bit UpdateExitPopupSalesRule, where the empty id also
        // caused save() to INSERT a blank rule.
        $ruleId = $this->findRuleId();

        if ($ruleId < 1) {
            $this->logger->error(
                '[ExitPopupCouponService] Sales rule "' . CreateExitPopupSalesRule::RULE_NAME
                . '" not found - run bin/magento setup:upgrade.'
            );
            return null;
        }

        $rule = $this->ruleFactory->create();
        $this->ruleResource->load($rule, $ruleId);

        if (!$rule->getId()) {
            $this->logger->error(
                '[ExitPopupCouponService] Sales rule id ' . $ruleId . ' could not be loaded.'
            );
            return null;
        }

        // Keep the rule's actual discount in sync with the admin setting before
        // handing out a new code, so a percent change takes effect immediately.
        $currentPercent = $this->getDiscountPercent();
        if ((float) $rule->getDiscountAmount() !== (float) $currentPercent) {
            $rule->setDiscountAmount($currentPercent);
            $this->ruleResource->save($rule);
        }

        try {
            $spec = $this->specFactory->create();
            $spec->setRuleId((int) $rule->getId());
            $spec->setQuantity(1);
            $spec->setLength(self::COUPON_LENGTH);
            $spec->setFormat(self::COUPON_FORMAT_ALPHANUMERIC);

            $codes = $this->couponManagement->generate($spec);
            $code = $codes[0] ?? null;

            if ($code !== null) {
                $this->applyExpiry($code);
            }

            return $code;
        } catch (\Throwable $exception) {
            $this->logger->error('[ExitPopupCouponService] generateCode failed: ' . $exception->getMessage());
            return null;
        }
    }

    /**
     * The expiry of the most recently issued code, as 'Y-m-d H:i:s', or null when
     * no timer is configured.
     *
     * Exposed so the claim row can mirror it without re-reading salesrule_coupon.
     */
    public function getExpiryFor(string $couponCode): ?string
    {
        $coupon = $this->couponFactory->create();
        $coupon->loadByCode($couponCode);

        $expiry = $coupon->getExpirationDate();

        return $expiry ? (string) $expiry : null;
    }

    /**
     * The rule's id, looked up by name with a plain query.
     *
     * Deliberately not $rule->load($name, 'name') - see generateCode().
     */
    private function findRuleId(): int
    {
        $connection = $this->resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('salesrule'), ['rule_id'])
                ->where('name = ?', CreateExitPopupSalesRule::RULE_NAME)
                ->limit(1)
        );
    }

    /**
     * Stamps the timer's deadline onto this one coupon.
     *
     * Per-coupon rather than the rule's own to_date, because each customer's
     * window starts when *they* claimed - the countdown they saw in the popup is
     * then the countdown that actually applies. A shared rule-level date would
     * make the popup's timer misleading for anyone claiming late in the campaign.
     *
     * The popup's countdown reads the same "timer_hours" setting, so the two stay
     * in step.
     */
    private function applyExpiry(string $couponCode): void
    {
        $hours = (int) $this->scopeConfig->getValue(self::XML_PATH_TIMER_HOURS, ScopeInterface::SCOPE_STORE);
        if ($hours < 1) {
            // No timer configured - leave the code open-ended.
            return;
        }

        try {
            $coupon = $this->couponFactory->create();
            $coupon->loadByCode($couponCode);
            if (!$coupon->getId()) {
                return;
            }

            $expiry = (new \DateTime('now', new \DateTimeZone('UTC')))
                ->add(new \DateInterval('PT' . $hours . 'H'));

            $coupon->setExpirationDate($expiry->format('Y-m-d H:i:s'));
            $this->couponResource->save($coupon);
        } catch (\Throwable $exception) {
            // A code without an expiry is still usable, so this must not fail
            // the claim - the customer keeps a working coupon either way.
            $this->logger->error(
                '[ExitPopupCouponService] could not set expiry on ' . $couponCode
                . ': ' . $exception->getMessage()
            );
        }
    }
}
