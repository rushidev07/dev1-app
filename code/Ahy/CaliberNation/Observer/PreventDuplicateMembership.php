<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Blocks an ACTIVE member from adding the membership product to the cart — they
 * already have an active membership and renewals are automatic (cron/auto-renew),
 * so re-purchasing makes no sense.
 *
 * Expired / cancelled / non-members are NOT blocked (they legitimately (re)buy —
 * e.g. the win-back flow), because the guard keys on isActiveMember() only.
 * Frontend-scoped (etc/frontend/events.xml) so admin order creation is unaffected.
 */
class PreventDuplicateMembership implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly CustomerSession $customerSession
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $product = $observer->getEvent()->getData('product');
        if (!$product || $product->getSku() !== $this->config->getMembershipSku()) {
            return;
        }

        if ($this->memberAccess->isActiveMember((int) $this->customerSession->getCustomerId())) {
            throw new LocalizedException(
                __('You already have an active Caliber Nation membership — no need to buy it again. It renews automatically; you can manage it in My Account.')
            );
        }
    }
}
