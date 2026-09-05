<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Drives the "Join Caliber Nation" entry-point banners (cart, registration, etc.).
 * The banner links to the account-first landing page; it is hidden for active members.
 */
class JoinCta implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly UrlInterface $urlBuilder,
        private readonly Config $config
    ) {}

    /**
     * Show only to guests and logged-in customers who have NEVER had a membership.
     * Customers with any membership record (active / expired / cancelled) are handled
     * elsewhere — active members see nothing; expired members see the renewal prompt.
     */
    public function shouldShow(): bool
    {
        if (!$this->config->isEnabled()) {
            return false; // program off → no join banners anywhere
        }
        if (!$this->customerSession->isLoggedIn()) {
            return true; // guest → invite to join
        }
        try {
            $this->membershipRepository->getByCustomerId((int) $this->customerSession->getCustomerId());
            return false; // has a membership record of some kind
        } catch (NoSuchEntityException) {
            return true; // logged-in but never a member
        }
    }

    public function isActiveMember(): bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }
        try {
            $membership = $this->membershipRepository->getByCustomerId(
                (int) $this->customerSession->getCustomerId()
            );
            return $membership->getStatus() === MembershipInterface::STATUS_ACTIVE;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    public function getJoinUrl(): string
    {
        return $this->urlBuilder->getUrl('caliber-nation');
    }
}
