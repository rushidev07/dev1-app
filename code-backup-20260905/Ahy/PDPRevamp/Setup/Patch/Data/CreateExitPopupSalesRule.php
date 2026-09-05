<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Creates the Cart Price Rule that backs the PDP exit-intent popup's "10% off
 * your first order" offer (see Block\Product\View\ExitIntentPopup and
 * Service\ExitPopupCouponService). Coupon type is "Specific Coupon" with no
 * fixed code on the rule itself - Service\ExitPopupCouponService generates a
 * brand new single-use code under this same rule for every subscriber via
 * Magento's standard coupon-generation API, so the discount is real and each
 * code only redeems once, rather than the earlier version of this popup
 * (which promised a code but never actually created one).
 *
 * The discount amount here is just the starting default - it's kept in sync
 * with the admin-configured percent (Stores > Configuration > PDP Exit
 * Intent Popup > Discount Percent) by the service, at the moment each new
 * code is generated.
 */
class CreateExitPopupSalesRule implements DataPatchInterface
{
    public const RULE_NAME = 'PDP Exit Intent Popup - Welcome Discount';

    private ModuleDataSetupInterface $moduleDataSetup;
    private RuleFactory $ruleFactory;
    private RuleResource $ruleResource;
    private StoreManagerInterface $storeManager;
    private GroupRepositoryInterface $groupRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private State $appState;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        RuleFactory $ruleFactory,
        RuleResource $ruleResource,
        StoreManagerInterface $storeManager,
        GroupRepositoryInterface $groupRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        State $appState
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->ruleFactory = $ruleFactory;
        $this->ruleResource = $ruleResource;
        $this->storeManager = $storeManager;
        $this->groupRepository = $groupRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->appState = $appState;
    }

    public function apply(): self
    {
        // Saving a Sales Rule touches area-scoped services (pricing/translate)
        // that assume a bootstrapped area - setup:upgrade runs with none set,
        // which is exactly what threw "Area code is not set" here.
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            // Already set - fine.
        }

        $this->moduleDataSetup->getConnection()->startSetup();

        $rule = $this->ruleFactory->create();
        $rule->load(self::RULE_NAME, 'name');

        if (!$rule->getId()) {
            $websiteIds = array_map(
                static fn ($website) => (int) $website->getId(),
                $this->storeManager->getWebsites()
            );

            $groupIds = array_map(
                static fn ($group) => (int) $group->getId(),
                $this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems()
            );

            $rule->setName(self::RULE_NAME)
                ->setDescription('Exit-intent popup discount')
                ->setIsActive(true)
                ->setWebsiteIds($websiteIds)
                ->setCustomerGroupIds($groupIds)
                ->setCouponType(Rule::COUPON_TYPE_SPECIFIC)
                ->setUsesPerCoupon(1)
                ->setSimpleAction('by_percent')
                ->setDiscountAmount(10)
                ->setDiscountStep(0)
                ->setStopRulesProcessing(false)
                ->setIsAdvanced(true)
                ->setSortOrder(0)
                ->setSimpleFreeShipping(0);

            $this->ruleResource->save($rule);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
