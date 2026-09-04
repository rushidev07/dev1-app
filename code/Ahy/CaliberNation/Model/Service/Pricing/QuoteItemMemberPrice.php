<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service\Pricing;

use Ahy\CaliberNation\Api\Data\MemberPriceInterface;
use Ahy\CaliberNation\Api\Data\MemberPriceInterfaceFactory;
use Ahy\CaliberNation\Api\QuoteItemMemberPriceInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Item;

/**
 * Storefront implementation of the cart-line member-price contract.
 *
 * Deliberately mirrors Observer\ApplyMemberPrice: same eligibility rules, same
 * product reload, same price basis ($product->getPrice() through
 * MemberPriceResolver). ApplyMemberPrice decides what the customer is CHARGED;
 * this decides what is DISPLAYED. Keeping the inputs identical is what stops the
 * two drifting into showing one number and charging another.
 *
 * Results are memoised per quote item for the life of the request — a cart
 * section renders every line, and each resolve costs a product load.
 */
class QuoteItemMemberPrice implements QuoteItemMemberPriceInterface
{
    /** @var array<int,MemberPriceInterface|null> */
    private array $cache = [];

    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly MemberPriceResolver $resolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerSession $customerSession,
        private readonly MemberPriceInterfaceFactory $resultFactory
    ) {}

    public function getForQuoteItem(Item $item): ?MemberPriceInterface
    {
        $cacheKey = (int) $item->getId();
        if ($cacheKey > 0 && array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $result = $this->resolve($item);

        if ($cacheKey > 0) {
            $this->cache[$cacheKey] = $result;
        }
        return $result;
    }

    private function resolve(Item $item): ?MemberPriceInterface
    {
        if (!$this->config->isEnabled() || !$this->config->isPricingEnabled()) {
            return null;
        }

        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }
        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$this->memberAccess->isActiveMember($customerId)) {
            return null;
        }

        // The membership product carries its own price; child rows are priced
        // through their parent line.
        if ($item->getSku() === $this->config->getMembershipSku() || $item->getParentItemId()) {
            return null;
        }

        $product = $this->loadFullProduct($item);
        if (!$product) {
            return null;
        }

        $regular     = (float) $product->getPrice();
        $priceResult = $this->resolver->resolveForProduct($product, $regular);
        if (!$priceResult->hasDiscount()) {
            return null;
        }

        /** @var MemberPriceInterface $dto */
        $dto = $this->resultFactory->create();
        $dto->setProductId((int) $product->getId());
        $dto->setRegularPrice($priceResult->regularPrice);
        $dto->setMemberPrice($priceResult->memberPrice);
        $dto->setDiscount($priceResult->discount);
        $dto->setHasDiscount(true);
        // Only ever reached for an active member — see the guard above.
        $dto->setIsMember(true);

        return $dto;
    }

    /**
     * A quote item's own product is a lightweight object that loses the custom EAV
     * attributes (caliber_member_discount_*) once the quote is reloaded from the
     * DB. Reload through the repository so the resolver sees them. Mirrors
     * ApplyMemberPrice::loadFullProduct().
     */
    private function loadFullProduct(Item $item): ?ProductInterface
    {
        $itemProduct = $item->getProduct();
        if (!$itemProduct || !$itemProduct->getId()) {
            return null;
        }
        try {
            return $this->productRepository->getById(
                (int) $itemProduct->getId(),
                false,
                $item->getStoreId() === null ? null : (int) $item->getStoreId()
            );
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
