<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * An ACTIVE member must never carry the membership product in their cart — they
 * already have an active membership and it renews automatically. PreventDuplicateMembership
 * blocks *adding* it, but an item can still be left behind: e.g. it was auto-added
 * while the member was expired (AutoAddMembershipForExpired), then the member
 * reactivated — leaving a stale line.
 *
 * IMPORTANT — this runs on the cart / checkout page PRE-DISPATCH, NOT on
 * sales_quote_collect_totals_before. Removing a quote item *inside* the totals
 * collection collided with Amasty Free Gift's gift engine (which manages its gift
 * in the same collect pass): the gift got dropped and never re-added, even though a
 * qualifying product was still present. Cleaning the cart *before* the page's totals
 * collection lets Amasty collect on a clean cart and keep the gift.
 */
class RemoveActiveMemberMembership implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            return;
        }

        if (!$quote || !$quote->getId() || !$quote->getCustomerId()) {
            return;
        }

        if (!$this->memberAccess->isActiveMember((int) $quote->getCustomerId())) {
            return;
        }

        $sku     = $this->config->getMembershipSku();
        $removed = false;
        foreach ($quote->getAllItems() as $item) {
            if ($item->getSku() === $sku) {
                $quote->removeItem($item->getId());
                $removed = true;
            }
        }

        if (!$removed) {
            return;
        }

        // Persist the clean cart now, BEFORE the page renders and collects totals,
        // so Amasty Free Gift re-collects on a cart without the membership and keeps
        // any qualifying free gift.
        try {
            $quote->collectTotals();
            $this->cartRepository->save($quote);
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] failed removing stale membership from active member cart: ' . $e->getMessage());
        }
    }
}
