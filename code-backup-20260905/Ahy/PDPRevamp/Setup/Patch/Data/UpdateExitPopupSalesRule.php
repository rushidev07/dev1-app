<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Tightens the exit-popup Cart Price Rule created by CreateExitPopupSalesRule.
 *
 * As created, the rule had no conditions and no actions filter, so
 * "by_percent 10" discounted the *entire cart subtotal* - not just the product
 * the popup was shown on. It also allowed unlimited redemptions per customer and
 * let guests redeem.
 *
 * Four changes:
 *
 * 1. Actions filter on pdp_exit_popup_enabled = 1, so only products opted into
 *    the popup can be discounted. This is an *actions* filter, not a conditions
 *    one: conditions decide whether the rule applies at all, actions decide which
 *    cart items it discounts. Filtering on the attribute rather than a SKU keeps
 *    this to one shared rule - a per-claim rule would grow salesrule unbounded
 *    and flood the admin Cart Price Rules grid.
 *
 * 2. discount_qty = 1, so a cart holding several eligible items only gets one
 *    unit discounted.
 *
 * 3. uses_per_customer = 1 (was 0 = unlimited), so one account cannot redeem two
 *    different codes from this rule.
 *
 * 4. Customer group 0 (NOT LOGGED IN) removed, so a guest can claim a code but
 *    not redeem it - Magento refuses it in a guest cart natively. Group 6
 *    (Caliber Nation Member) is deliberately kept: stacking with member pricing
 *    was an explicit decision.
 *
 * Written as direct SQL rather than through Magento\SalesRule\Model\Rule for two
 * reasons found the hard way:
 *
 * - Loading that model throws "Area code is not set" inside a data patch, which
 *   has no area context.
 * - $rule->load($name, 'name') does not reliably hydrate it even outside a
 *   patch - it returned an empty id with the row present, and the following
 *   save() INSERTed a blank rule instead of updating the real one.
 *
 * The three columns and one link table touched here are stable across 2.4, and
 * bypassing the model also avoids its save() rewriting unrelated serialized
 * fields.
 *
 * Idempotent: re-running re-applies the same values.
 */
class UpdateExitPopupSalesRule implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $setup = $this->moduleDataSetup;
        $connection = $setup->getConnection();

        $ruleTable = $setup->getTable('salesrule');

        $ruleId = (int) $connection->fetchOne(
            $connection->select()
                ->from($ruleTable, ['rule_id'])
                ->where('name = ?', CreateExitPopupSalesRule::RULE_NAME)
                ->limit(1)
        );

        if ($ruleId < 1) {
            // CreateExitPopupSalesRule has not run yet; nothing to tighten.
            return $this;
        }

        $connection->update(
            $ruleTable,
            [
                'uses_per_customer' => 1,
                'discount_qty' => 1,
                // Without this, CouponManagement::generate() refuses with
                // "Specified rule does not allow automatic coupon generation" -
                // coupon_type = SPECIFIC alone is not enough, the rule has to
                // opt in to programmatic generation as well. This is what the
                // admin's "Use Auto Generation" checkbox sets.
                'use_auto_generation' => 1,
                'actions_serialized' => $this->buildActionsFilter(),
            ],
            ['rule_id = ?' => $ruleId]
        );

        $this->restrictToLoggedInGroups($ruleId);

        return $this;
    }

    /**
     * Every customer group except 0 (NOT LOGGED IN).
     *
     * Read from the DB rather than hardcoded, so a store with custom groups keeps
     * them.
     */
    private function restrictToLoggedInGroups(int $ruleId): void
    {
        $setup = $this->moduleDataSetup;
        $connection = $setup->getConnection();
        $linkTable = $setup->getTable('salesrule_customer_group');

        $groupIds = $connection->fetchCol(
            $connection->select()
                ->from($setup->getTable('customer_group'), ['customer_group_id'])
                ->where('customer_group_id > ?', 0)
        );

        if (!$groupIds) {
            return;
        }

        $connection->delete($linkTable, ['rule_id = ?' => $ruleId]);

        $rows = [];
        foreach ($groupIds as $groupId) {
            $rows[] = ['rule_id' => $ruleId, 'customer_group_id' => (int) $groupId];
        }
        $connection->insertMultiple($linkTable, $rows);
    }

    /**
     * Actions tree: apply to cart items whose pdp_exit_popup_enabled is 1.
     *
     * Serialized directly because a data patch has no request context to build
     * the condition model tree from. The shape is stable across Magento 2.4.
     */
    private function buildActionsFilter(): string
    {
        return json_encode([
            'type' => \Magento\SalesRule\Model\Rule\Condition\Product\Combine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => 'all',
            'conditions' => [
                [
                    'type' => \Magento\SalesRule\Model\Rule\Condition\Product::class,
                    'attribute' => CreateExitPopupEnabledAttribute::ATTRIBUTE_CODE,
                    'operator' => '==',
                    'value' => '1',
                    'is_value_processed' => false,
                ],
            ],
        ]);
    }

    public static function getDependencies(): array
    {
        // Needs the rule to exist, and the attribute to be promo-rule usable
        // before it can be referenced in an actions filter.
        return [
            CreateExitPopupSalesRule::class,
            CreateExitPopupEnabledAttribute::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}
