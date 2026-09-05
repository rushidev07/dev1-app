<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Identifies expired (lapsed) members and win-back eligibility for the Day-31
 * discount. "Expired" = membership status EXPIRED; days-since-expiry is measured
 * from the renewal_date at which it lapsed.
 */
class ExpiredMemberLocator
{
    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly Config $config
    ) {}

    public function getMembership(int $customerId): ?MembershipInterface
    {
        try {
            return $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    public function isExpired(int $customerId): bool
    {
        return $this->getMembership($customerId)?->getStatus() === MembershipInterface::STATUS_EXPIRED;
    }

    /** Days since the membership lapsed (its renewal_date), or null if not expired. */
    public function getDaysSinceExpiry(int $customerId): ?int
    {
        $membership = $this->getMembership($customerId);
        if (!$membership || $membership->getStatus() !== MembershipInterface::STATUS_EXPIRED) {
            return null;
        }
        $renewal = $membership->getRenewalDate();
        if (!$renewal) {
            return null;
        }
        return (int) floor((time() - strtotime($renewal)) / 86400);
    }

    /**
     * Eligible for the win-back discount = feature enabled AND expired AND
     * expired for at least the configured threshold (e.g. 30 → from day 31).
     */
    public function isWinbackEligible(int $customerId): bool
    {
        if (!$this->config->isWinbackEnabled()) {
            return false;
        }
        $days = $this->getDaysSinceExpiry($customerId);
        return $days !== null && $days >= $this->config->getWinbackDaysThreshold();
    }
}
