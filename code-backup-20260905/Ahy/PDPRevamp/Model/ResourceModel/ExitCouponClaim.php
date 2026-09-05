<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * AbstractDb resource for the claims grid - see Model\ExitCouponClaim for why
 * this exists next to the plain ResourceConnection-based ExitCoupon resource.
 */
class ExitCouponClaim extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ahy_pdprevamp_exit_coupon', 'entity_id');
    }
}
