<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Links a freshly-vaulted card to the customer's membership (B0).
 *
 * During signup checkout the Authorize.Net flow vaults the card AFTER the order
 * is placed — i.e. after the activation observer runs — so the membership row is
 * created with an empty payment_token_id. This afterSave plugin fills that gap:
 * whenever a vault token is saved for a customer whose membership has no linked
 * token yet, it links this token. The membership needs it for auto-renewal.
 */
class LinkVaultTokenToMembership
{
    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param PaymentTokenInterface $result
     * @return PaymentTokenInterface
     */
    public function afterSave(PaymentTokenRepositoryInterface $subject, $result)
    {
        try {
            if (!$this->config->isEnabled()) {
                return $result;
            }
            if (!$result instanceof PaymentTokenInterface || !$result->getIsActive()) {
                return $result;
            }

            $customerId = (int) $result->getCustomerId();
            if (!$customerId) {
                return $result;
            }

            $membership = $this->membershipRepository->getByCustomerId($customerId);

            // Only fill it if not already linked.
            if (!$membership->getPaymentTokenId()) {
                $membership->setPaymentTokenId((int) $result->getEntityId());
                $this->membershipRepository->save($membership);
                $this->logger->info(
                    '[CaliberNation] Linked vault token ' . $result->getEntityId()
                    . ' to membership of customer ' . $customerId
                );
            }
        } catch (NoSuchEntityException) {
            // Customer has no membership — nothing to link.
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] LinkVaultTokenToMembership failed: ' . $e->getMessage());
        }

        return $result;
    }
}
