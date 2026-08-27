<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Ahy\CaliberNation\Model\ResourceModel\Membership\CollectionFactory;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Psr\Log\LoggerInterface;

/**
 * One-off backfill: link the latest active vault token to any membership that
 * has no payment_token_id (created before the LinkVaultTokenToMembership plugin).
 */
class BackfillMembershipTokens implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CollectionFactory $membershipCollectionFactory,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly PaymentTokenManagementInterface $paymentTokenManagement,
        private readonly LoggerInterface $logger
    ) {}

    public function apply(): self
    {
        $collection = $this->membershipCollectionFactory->create()
            ->addFieldToFilter('payment_token_id', ['null' => true]);

        foreach ($collection as $membership) {
            $customerId = (int) $membership->getCustomerId();
            $latestId = null;
            foreach ($this->paymentTokenManagement->getListByCustomerId($customerId) as $token) {
                if ($token->getIsActive()) {
                    $latestId = max((int) $latestId, (int) $token->getEntityId());
                }
            }
            if ($latestId) {
                try {
                    $membership->setPaymentTokenId($latestId);
                    $this->membershipRepository->save($membership);
                } catch (\Exception $e) {
                    $this->logger->error('[CaliberNation] token backfill failed for customer ' . $customerId . ': ' . $e->getMessage());
                }
            }
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
