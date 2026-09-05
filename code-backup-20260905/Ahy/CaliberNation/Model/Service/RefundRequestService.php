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
                ]
            );
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] early cancellation record failed: ' . $e->getMessage());
        }
    }
}
