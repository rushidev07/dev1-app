<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\ExpiredMemberLocator;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Drives the "your membership expired — renew" prompt shown to lapsed members
 * across touchpoints. If the member is win-back eligible (past the day threshold),
 * the prompt advertises the discounted price.
 */
class RenewalPrompt implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ExpiredMemberLocator $expiredMemberLocator,
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {}

    public function shouldShow(): bool
    {
        return $this->config->isEnabled()
            && $this->customerSession->isLoggedIn()
            && $this->expiredMemberLocator->isExpired((int) $this->customerSession->getCustomerId());
    }

    public function isWinbackEligible(): bool
    {
        return $this->customerSession->isLoggedIn()
            && $this->expiredMemberLocator->isWinbackEligible((int) $this->customerSession->getCustomerId());
    }

    public function getMessage(): string
    {
        if ($this->isWinbackEligible()) {
            return sprintf(
                'Your Caliber Nation membership has expired. Come back for a special price of %s.',
                $this->getWinbackPriceFormatted()
            );
        }
        return 'Your Caliber Nation membership has expired. Renew now to restore your member benefits.';
    }

    public function getWinbackPriceFormatted(): string
    {
        return '$' . number_format($this->config->getWinbackPrice(), 2);
    }

    /**
     * Landing page flagged to scroll to the signup/renew form — the page is a long
     * marketing page, so a bare URL would drop the lapsed member at the hero and make
     * them hunt for the form they came to fill in.
     */
    public function getRenewUrl(): string
    {
        return $this->urlBuilder->getUrl('caliber-nation', [
            '_query' => [Config::SIGNUP_SCROLL_PARAM => Config::SIGNUP_SCROLL_VALUE],
        ]);
    }
}
