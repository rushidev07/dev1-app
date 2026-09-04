<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\EarlyAccessService;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Storefront ViewModel for the early-access badge on PDP / PLP.
 *
 * Resolves badge text and active status for a product.
 * Results cached per product per request.
 */
class EarlyAccessBadge implements ArgumentInterface
{
    /** @var array<int,array<string,mixed>|null> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly EarlyAccessService $earlyAccessService,
        private readonly MemberAccess $memberAccess,
        private readonly CustomerSession $customerSession
    ) {}

    /** Whether the feature is enabled at all. */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isEarlyAccessEnabled();
    }

    /**
     * Resolve early-access display data for a product.
     *
     * Returns null when the feature is off or the product is not flagged.
     * Returns an array when a badge should be shown:
     *
     *   [
     *     'member_badge'     => string,
     *     'non_member_badge' => string,
     *   ]
     */
    public function resolve(ProductInterface $product): ?array
    {
        $id = (int) $product->getId();
        if (array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        if (!$this->isEnabled()) {
            return $this->cache[$id] = null;
        }

        if ($this->earlyAccessService->getWindowStatus($id) === 'disabled') {
            return $this->cache[$id] = null;
        }

        return $this->cache[$id] = [
            'member_badge'     => $this->config->getEarlyAccessMemberBadgeText(),
            'non_member_badge' => $this->config->getEarlyAccessNonMemberBadgeText(),
        ];
    }

    /** Whether the current visitor is an active member. */
    public function isMember(): bool
    {
        return $this->memberAccess->isActiveMember((int) $this->customerSession->getCustomerId());
    }
}
