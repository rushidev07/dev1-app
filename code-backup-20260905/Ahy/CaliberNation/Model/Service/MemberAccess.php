<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Single source of truth for "is this customer entitled to Caliber Nation member
 * benefits right now?". Used by the pricing engine (member price), member-only
 * gates, savings recording, etc. Result cached per request.
 *
 * Entitled means EITHER:
 *   - status = active, OR
 *   - status = cancelled but still within the paid-through window (renewal_date in
 *     the future). A cancelled member has only switched off auto-renew; they keep
 *     the benefits they already paid for until their term ends, at which point the
 *     daily cron flips them to expired (see MembershipManagementService / ProcessRenewals).
 *
 * NOTE: renewal_pending is intentionally NOT entitled — the paid term has ended and
 * the renewal charge has not (yet) succeeded, so no benefits until it does.
 */
class MemberAccess
{
    /** @var array<int,bool> */
    private array $cache = [];

    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository
    ) {}

    public function isActiveMember(?int $customerId): bool
    {
        if (!$customerId) {
            return false;
        }
        if (isset($this->cache[$customerId])) {
            return $this->cache[$customerId];
        }

        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
            $status     = $membership->getStatus();

            $entitled = $status === MembershipInterface::STATUS_ACTIVE
                || (
                    $status === MembershipInterface::STATUS_CANCELLED
                    && ($renewal = $membership->getRenewalDate())
                    && strtotime((string) $renewal) > time()
                );
        } catch (NoSuchEntityException) {
            $entitled = false;
        }

        return $this->cache[$customerId] = (bool) $entitled;
    }
}
