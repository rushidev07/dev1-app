<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

/**
 * "Which products are actually bought alongside this one?", from order history.
 *
 * Backs tier 1 of the FBT section. Everything here exists because the naive
 * version - COUNT(*) of shared orders, highest first - was tested against this
 * store's data and returns a free promotional decal for every product.
 *
 * Plain ResourceConnection rather than a collection: this is one read-only
 * aggregate query, and the caller wants ids, not hydrated models.
 */
class ProductAffinity
{
    /**
     * Minimum shared orders before a pairing is trusted.
     *
     * Any ratio-based score needs a support floor or tiny samples dominate. In
     * this store's data a partner sharing exactly ONE order - and appearing in no
     * other order at all - scored 1.000 and outranked a partner sharing five
     * orders at 0.417. Three is low enough to be useful on a young dataset and
     * high enough to exclude a single coincidence.
     */
    private const DEFAULT_MIN_SHARED_ORDERS = 3;

    /**
     * A product appearing in more than this share of ALL orders is treated as a
     * store-wide promotion rather than a companion, and dropped.
     *
     * This is the generic net behind the explicit SKU exclusion list. The known
     * offender here is a free decal auto-added to ~30% of orders: it is not
     * associated with any particular product, it is associated with everything.
     * Normalising the score does not fix that - lift still ranked it first - so it
     * has to be excluded outright.
     */
    private const UBIQUITY_THRESHOLD = 0.20;

    /**
     * Order states that are not evidence of a purchase.
     *
     * Over a fifth of this store's orders sit in these two states, so including
     * them would materially skew the result.
     */
    private const IGNORED_ORDER_STATES = [Order::STATE_CANCELED, Order::STATE_CLOSED];

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Product ids bought alongside $productId, best first.
     *
     * Ranked by lift - P(B|A) / P(B) - rather than raw co-purchase count. Lift
     * asks "is B disproportionately likely in orders containing A?", which is the
     * actual question; a raw count just finds whatever is popular.
     *
     * @param int[] $excludeProductIds resolved from the configured SKU list
     * @return int[]
     */
    public function getRelatedProductIds(
        int $productId,
        int $limit,
        array $excludeProductIds = [],
        ?int $minSharedOrders = null
    ): array {
        if ($productId < 1 || $limit < 1) {
            return [];
        }

        $minShared = $minSharedOrders !== null && $minSharedOrders > 0
            ? $minSharedOrders
            : self::DEFAULT_MIN_SHARED_ORDERS;

        $connection = $this->resourceConnection->getConnection();
        $itemTable = $this->resourceConnection->getTableName('sales_order_item');
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $totalOrders = $this->getQualifyingOrderCount();
        if ($totalOrders < 1) {
            return [];
        }

        // How many orders contain the anchor product - the denominator of P(B|A).
        $anchorOrders = (int) $connection->fetchOne(
            $connection->select()
                ->from(['i' => $itemTable], ['COUNT(DISTINCT i.order_id)'])
                ->join(['o' => $orderTable], 'o.entity_id = i.order_id', [])
                ->where('i.product_id = ?', $productId)
                ->where('i.parent_item_id IS NULL')
                ->where('o.state NOT IN (?)', self::IGNORED_ORDER_STATES)
        );

        if ($anchorOrders < 1) {
            return [];
        }

        // Per-product order counts, used both to compute lift and to spot
        // ubiquitous items. Built as a subselect rather than a temp table so the
        // whole thing stays one round trip.
        $totalsSelect = $connection->select()
            ->from(['ti' => $itemTable], [
                'product_id' => 'ti.product_id',
                'order_count' => 'COUNT(DISTINCT ti.order_id)',
            ])
            // Alias deliberately not "to" - that is a reserved word and the
            // query fails to parse with it.
            ->join(['tord' => $orderTable], 'tord.entity_id = ti.order_id', [])
            ->where('ti.parent_item_id IS NULL')
            ->where('tord.state NOT IN (?)', self::IGNORED_ORDER_STATES)
            ->group('ti.product_id');

        // lift = (shared / anchorOrders) / (partnerTotal / totalOrders)
        $liftExpression = sprintf(
            '(COUNT(DISTINCT a.order_id) / %d) / (t.order_count / %d)',
            $anchorOrders,
            $totalOrders
        );

        $select = $connection->select()
            ->from(['a' => $itemTable], [])
            ->join(
                ['b' => $itemTable],
                'b.order_id = a.order_id AND b.product_id <> a.product_id',
                []
            )
            ->join(['o' => $orderTable], 'o.entity_id = a.order_id', [])
            ->join(['t' => $totalsSelect], 't.product_id = b.product_id', [])
            ->columns([
                'product_id' => 'b.product_id',
                'shared_orders' => 'COUNT(DISTINCT a.order_id)',
                'lift' => new \Zend_Db_Expr($liftExpression),
            ])
            ->where('a.product_id = ?', $productId)
            // Required on BOTH sides: a configurable writes a parent row and a
            // child row per line, so without this every such product is counted
            // twice.
            ->where('a.parent_item_id IS NULL')
            ->where('b.parent_item_id IS NULL')
            ->where('o.state NOT IN (?)', self::IGNORED_ORDER_STATES)
            // The ubiquity filter. Applied in SQL rather than in PHP so a
            // store-wide freebie never occupies a result slot in the first place.
            ->where('t.order_count <= ?', (int) ceil($totalOrders * self::UBIQUITY_THRESHOLD))
            ->group('b.product_id')
            ->having('shared_orders >= ?', $minShared)
            ->order('lift DESC')
            ->order('shared_orders DESC')
            ->limit($limit);

        if ($excludeProductIds) {
            $select->where('b.product_id NOT IN (?)', $excludeProductIds);
        }

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Orders that count as evidence - i.e. not cancelled or closed.
     */
    private function getQualifyingOrderCount(): int
    {
        $connection = $this->resourceConnection->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from(
                    ['o' => $this->resourceConnection->getTableName('sales_order')],
                    ['COUNT(*)']
                )
                ->where('o.state NOT IN (?)', self::IGNORED_ORDER_STATES)
        );
    }
}
