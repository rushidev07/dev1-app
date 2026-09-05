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
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Exposes which saved-card (vault token) the current customer's membership uses for
 * auto-renewal, so the saved-cards UI can warn before that specific card is deleted.
 * Returns a token id only when the membership is active AND auto-renew is on — i.e.
 * exactly when deleting the card would silently break the next renewal.
 */
class MembershipCard implements ArgumentInterface
{
    private ?int $tokenId = null;
    private bool $resolved = false;

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly Config $config
    ) {}

    /** The vault token id used for renewal, or null if none / not auto-renewing. */
    public function getRenewalTokenId(): ?int
    {
        if ($this->resolved) {
            return $this->tokenId;
        }
        $this->resolved = true;

        if (!$this->config->isEnabled()) {
            return null;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$customerId) {
            return null;
        }
        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return null;
        }

        $isAutoRenewing = $membership->getStatus() === MembershipInterface::STATUS_ACTIVE
            && (int) $membership->getAutoRenew() === 1
            && $membership->getPaymentTokenId();

        return $this->tokenId = $isAutoRenewing ? (int) $membership->getPaymentTokenId() : null;
    }

    /** Whether the given vault token id is the membership's auto-renewal card. */
    public function isRenewalCard(int $tokenId): bool
    {
        return $this->getRenewalTokenId() === $tokenId;
    }
}
