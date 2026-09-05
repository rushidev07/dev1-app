<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Writes membership + admin activity to ahy_caliber_nation_activity_log.
 * Failures are swallowed (audit logging must never break a business action).
 */
class ActivityLogger
{
    private const TABLE = 'ahy_caliber_nation_activity_log';

    /**#@+ Known action codes. */
    public const ACTION_CREATED       = 'created';
    public const ACTION_CANCELLED     = 'cancelled';
    public const ACTION_RENEWED       = 'renewed';
    public const ACTION_STATUS_CHANGE = 'status_change';
    public const ACTION_AUTO_RENEW    = 'auto_renew_toggle';
    public const ACTION_CONFIG_CHANGE = 'config_change';
    public const ACTION_DISCOUNT_USED = 'discount_used';
    /**#@-*/

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {}

    public function log(
        ?int $customerId,
        string $action,
        ?string $detail = null,
        ?int $orderId = null,
        ?int $adminUserId = null
    ): void {
        try {
            $conn = $this->resource->getConnection();
            $conn->insert($this->resource->getTableName(self::TABLE), [
                'customer_id'   => $customerId,
                'admin_user_id' => $adminUserId,
                'action'        => $action,
                'detail'        => $detail,
                'order_id'      => $orderId,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] activity log write failed: ' . $e->getMessage());
        }
    }
}
