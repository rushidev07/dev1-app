<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\CustomerData;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Per-customer "cumulative member savings" exposed as a customer-data section, so
 * the value can be rendered in the (full-page-cached) header without leaking one
 * customer's total to another. The header reads it client-side from the
 * private-content-loaded event, exactly like the cart count.
 *
 * Section name: caliber-nation-savings (registered in etc/di.xml).
 * Shown to any member currently entitled to benefits (active, or cancelled but
 * still paid-through — see MemberAccess) with recorded savings, while the program
 * master switch is on.
 */
class MemberSavings implements SectionSourceInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {}

    /**
     * `member` = the visitor is an entitled member right now (drives per-customer
     * bits that must stay OUT of full-page-cached HTML, e.g. the PDP price teaser's
     * "You pay" vs "Members pay" label + Join link). `has`/`amount`/`formatted`
     * carry the cumulative savings for the header pill (member with savings > 0).
     *
     * @return array{has: bool, amount: float, formatted: string, member: bool}
     */
    public function getSectionData(): array
    {
        $empty = ['has' => false, 'amount' => 0.0, 'formatted' => '', 'member' => false];

        if (!$this->config->isEnabled() || !$this->customerSession->isLoggedIn()) {
            return $empty;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$this->memberAccess->isActiveMember($customerId)) {
            return $empty;
        }

        // Entitled member from here on — even if they have no recorded savings yet.
        $memberOnly = ['has' => false, 'amount' => 0.0, 'formatted' => '', 'member' => true];

        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return $memberOnly;
        }

        $amount = (float) $membership->getLifetimeSavings();
        if ($amount <= 0) {
            return $memberOnly;
        }

        return [
            'has'       => true,
            'amount'    => $amount,
            'formatted' => $this->priceCurrency->format($amount, false),
            'member'    => true,
        ];
    }
}
