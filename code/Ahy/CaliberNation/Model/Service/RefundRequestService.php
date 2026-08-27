<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Creates an early-cancellation record whenever a member cancels within the
 * configured refund window. One row per cancellation event. Uses raw
 * ResourceConnection so failures never break the cancellation flow.
 */
class RefundRequestService
{
    private const TABLE = 'ahy_caliber_nation_refund_request';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {}

    /**
     * Returns true if a refund has already been reviewed for this membership,
     * meaning the refund was processed and undo should be blocked.
     */
    public function isRefundReviewed(int $membershipId): bool
    {
        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);

            // Only the most-recent row matters: a new pending row (re-cancellation after
            // re-purchase) should NOT be blocked by an older reviewed row.
            $latestStatus = $conn->fetchOne(
                "SELECT status FROM {$table} WHERE membership_id = ? ORDER BY entity_id DESC LIMIT 1",
                [$membershipId]
            );

            return $latestStatus === 'reviewed';
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] isRefundReviewed check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find the most recent pending/reviewed refund request for this membership
     * and mark it as rejoined (member undid their cancellation).
     */
    public function markReinstated(int $membershipId): void
    {
        try {
            $conn  = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::TABLE);

            $conn->update(
                $table,
                ['status' => 'reinstated'],
                [
                    'membership_id = ?' => $membershipId,
                    'status IN (?)'     => ['pending', 'reviewed'],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] refund request reinstated stamp failed: ' . $e->getMessage());
        }
    }

    public function createIfEligible(int $customerId, int $membershipId, ?string $startDate): void
    {
        if (!$startDate || !$this->config->isEarlyCancellationEnabled()) {
            return;
        }

        $diffDays = (time() - strtotime($startDate)) / 86400;

        if ($diffDays > $this->config->getEarlyCancellationWindowDays()) {
            return;
        }

        try {
            $this->resource->getConnection()->insert(
                $this->resource->getTableName(self::TABLE),
                [
                    'customer_id'   => $customerId,
                    'membership_id' => $membershipId,
                    'start_date'    => $startDate,
                    'cancelled_at'  => (new \DateTime())->format('Y-m-d H:i:s'),
                    'status'        => 'pending',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] early cancellation record failed: ' . $e->getMessage());
        }
    }
}
