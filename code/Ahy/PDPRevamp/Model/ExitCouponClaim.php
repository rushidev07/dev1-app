<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Grid-facing model for ahy_pdprevamp_exit_coupon.
 *
 * The write path deliberately uses a plain ResourceConnection resource
 * (Model\ResourceModel\ExitCoupon) - a handful of keyed reads and writes needs
 * nothing more. A ui_component listing, though, has to have a real
 * AbstractDb collection to page, sort and filter through, so this pair exists
 * alongside it purely to serve the admin grid. Nothing in the claim flow uses it.
 */
class ExitCouponClaim extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\ExitCouponClaim::class);
    }
}
