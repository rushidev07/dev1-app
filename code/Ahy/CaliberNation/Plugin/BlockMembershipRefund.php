<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Plugin;

use Ahy\CaliberNation\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Enforces "no refunds" for the membership product — blocks any credit memo that
 * includes the membership line item.
 */
class BlockMembershipRefund
{
    public function __construct(
        private readonly Config $config
    ) {}

    /**
     * @throws LocalizedException
     */
    public function beforeRefund(
        CreditmemoManagementInterface $subject,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false
    ): array {
        if (!$this->config->isEnabled()) {
            return [$creditmemo, $offlineRequested];
        }

        $sku = $this->config->getMembershipSku();

        foreach ($creditmemo->getItems() as $item) {
            if ($item->getSku() === $sku && (float) $item->getQty() > 0) {
                throw new LocalizedException(
                    __('Caliber Nation membership purchases are non-refundable.')
                );
            }
        }

        return [$creditmemo, $offlineRequested];
    }
}
