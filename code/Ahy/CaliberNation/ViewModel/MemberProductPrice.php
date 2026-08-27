<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\ViewModel;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Ahy\CaliberNation\Model\Service\Pricing\MemberPriceResolver;
use Ahy\CaliberNation\Model\Service\Pricing\PriceResult;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Storefront helper for the regular-vs-Caliber price teaser on PDP / listing
 * (P5). The member price is shown to EVERYONE (P0 §1.7); isMember() tells the
 * template whether to phrase it "You pay" vs "Members pay". Results memoized
 * per product per request.
 */
class MemberProductPrice implements ArgumentInterface
{
    /** @var array<int,PriceResult> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly MemberPriceResolver $resolver,
        private readonly MemberAccess $memberAccess,
        private readonly CustomerSession $customerSession,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {}

    public function isEnabled(): bool
    {
        return $this->config->isEnabled() && $this->config->isPricingEnabled();
    }

    public function resolve(ProductInterface $product): PriceResult
    {
        $id = (int) $product->getId();
        return $this->cache[$id] ??= $this->resolver->resolveForProduct($product);
    }

    public function hasMemberPrice(ProductInterface $product): bool
    {
        return $this->isEnabled() && $this->resolve($product)->hasDiscount();
    }

    public function getFormattedRegularPrice(ProductInterface $product): string
    {
        return $this->priceCurrency->format($this->resolve($product)->regularPrice, false);
    }

    public function getFormattedMemberPrice(ProductInterface $product): string
    {
        return $this->priceCurrency->format($this->resolve($product)->memberPrice, false);
    }

    public function getFormattedSavings(ProductInterface $product): string
    {
        $r = $this->resolve($product);
        return $this->priceCurrency->format($r->regularPrice - $r->memberPrice, false);
    }

    /** Whether the current visitor is an active member (→ "You pay" vs "Members pay"). */
    public function isMember(): bool
    {
        return $this->memberAccess->isActiveMember((int) $this->customerSession->getCustomerId());
    }
}
