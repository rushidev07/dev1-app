<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\ActivityLogger;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * When a customer deletes the saved card their membership renews on, turn OFF
 * auto-renew and clear the (now unusable) token reference — otherwise the next
 * renewal cron would try to charge a deleted gateway profile and silently fail.
 *
 * Hooked at the repository level so it applies to every delete path (account
 * "Delete card", admin, etc.), not just one controller. Best-effort: never blocks
 * the delete itself.
 */
class DisableAutoRenewOnCardDelete
{
    public function __construct(
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {}

    public function afterDelete(
        PaymentTokenRepositoryInterface $subject,
        bool $result,
        PaymentTokenInterface $paymentToken
    ): bool {
        if (!$result || !$this->config->isEnabled()) {
            return $result;
        }

        try {
            $customerId = (int) $paymentToken->getCustomerId();
            if (!$customerId) {
                return $result;
            }

            $membership = $this->membershipRepository->getByCustomerId($customerId);

            $isMembershipCard = (int) $membership->getPaymentTokenId() === (int) $paymentToken->getEntityId();
            if ($isMembershipCard) {
                // Always drop the dangling reference to the deleted card; also turn
                // off auto-renew if it was on (can't renew without a card).
                $wasAutoRenew = (int) $membership->getAutoRenew() === 1;
                $membership->setPaymentTokenId(null);
                if ($wasAutoRenew) {
                    $membership->setAutoRenew(0);
                }
                $this->membershipRepository->save($membership);
                $this->activityLogger->log(
                    $customerId,
                    ActivityLogger::ACTION_AUTO_RENEW,
                    $wasAutoRenew
                        ? 'disabled — membership renewal card was deleted'
                        : 'renewal card removed — card was deleted'
                );
            }
        } catch (NoSuchEntityException) {
            // Customer has no membership — nothing to do.
        } catch (\Exception $e) {
            $this->logger->warning('[CaliberNation] disable auto-renew on card delete failed: ' . $e->getMessage());
        }

        return $result;
    }
}
