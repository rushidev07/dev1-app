<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\Authorizenet\Helper\Data as AuthnetHelper;
use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\ActivateMembership;
use Ahy\CaliberNation\Model\Service\CustomerService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on checkout_submit_all_after for EVERY order-placement path (standard
 * in-cart checkout and the programmatic landing-page order). If the order contains
 * the membership product, the customer is activated as a member.
 */
class ActivateOnOrderPlace implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ActivateMembership $activateMembership,
        private readonly CustomerService $customerService,
        private readonly PaymentTokenManagementInterface $paymentTokenManagement,
        private readonly CheckoutSession $checkoutSession,
        private readonly AuthnetHelper $authnetHelper,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        /** @var OrderInterface|null $order */
        $order = $observer->getEvent()->getData('order');
        if (!$order || !$this->orderHasMembership($order)) {
            return;
        }

        try {
            $customerId = (int) $order->getCustomerId();

            // Guest order → auto-create/link an account (spec §7).
            if (!$customerId) {
                $customerId = $this->customerService->getOrCreateByEmail(
                    (string) $order->getCustomerEmail(),
                    (string) $order->getCustomerFirstname(),
                    (string) $order->getCustomerLastname()
                );
            }

            if (!$customerId) {
                $this->logger->error('[CaliberNation] Could not resolve a customer for membership order ' . $order->getIncrementId());
                return;
            }

            // Bind a card for auto-renewal ONLY when the shopper chose to save one
            // during this checkout. The gateway (Ahy_Authorizenet) vaults a token
            // only when its "Save this card" box is ticked; on opt-out we bind
            // nothing, so the activation service keeps auto-renew OFF and no stale
            // pre-existing card is silently attached (card ⟺ auto-renew invariant).
            $tokenId = $this->saveCardWasRequested()
                ? $this->resolveLatestVaultTokenId($customerId)
                : null;

            $this->activateMembership->activate(
                $customerId,
                MembershipInterface::TIER_ANNUAL,
                $tokenId,
                (int) $order->getId()
            );
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] Activation on order place failed: ' . $e->getMessage());
        }
    }

    private function orderHasMembership(OrderInterface $order): bool
    {
        $sku = $this->config->getMembershipSku();
        foreach ($order->getItems() as $item) {
            if ($item->getSku() === $sku
                || (int) $item->getProductOptionByCode('is_caliber_nation_membership') === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Did the shopper tick "Save this card securely" at checkout? The Ahy_Authorizenet
     * Magewire component stores that choice in the checkout session under
     * 'saveCardCheckbox', AES-encrypted with the gateway's encryption key. We mirror
     * its _decryptSession() to read it. Any failure is treated as opt-out (safe: the
     * member can enable auto-renew from their account page once a card is on file).
     */
    private function saveCardWasRequested(): bool
    {
        try {
            $encrypted = $this->checkoutSession->getData('saveCardCheckbox');
            if (empty($encrypted)) {
                return false;
            }
            $key     = substr(hash('sha256', $this->authnetHelper->getEncryptionKey()), 0, 32);
            $decoded = base64_decode($encrypted);
            $iv      = substr($decoded, 0, 16);
            $data    = substr($decoded, 16);
            $value   = openssl_decrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

            return $value === 'true' || $value === '1';
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] could not read save-card flag: ' . $e->getMessage());
            return false;
        }
    }

    private function resolveLatestVaultTokenId(int $customerId): ?int
    {
        $latestId = null;
        foreach ($this->paymentTokenManagement->getListByCustomerId($customerId) as $token) {
            if (!$token->getIsActive()) {
                continue;
            }
            $latestId = max((int) $latestId, (int) $token->getEntityId());
        }
        return $latestId ?: null;
    }
}
