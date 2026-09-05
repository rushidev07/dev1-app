<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Observer;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Ahy\CaliberNation\Model\Service\Pricing\MemberPriceResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Applies the additive member price to catalog line items for ACTIVE members.
 *
 * Runs on sales_quote_collect_totals_before — i.e. BEFORE the cart price-rule /
 * discount collector — so the member price becomes the item's effective base
 * price and coupons/cart rules stack on top of it (P0 §1.5). Uses setCustomPrice,
 * mirroring the win-back observer.
 *
 * The membership product itself is skipped (it has its own price and is handled
 * by the win-back observer for lapsed members).
 */
class ApplyMemberPrice implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly MemberAccess $memberAccess,
        private readonly MemberPriceResolver $resolver,
        private readonly ProductRepositoryInterface $productRepository
    ) {}

    /**
     * A quote item's own getProduct() is a lightweight object that does NOT carry
     * custom EAV attributes (caliber_member_discount_*) once the quote is reloaded
     * from the DB — only the id/sku/price basics needed for totals. Re-loading via
     * the repository (which caches per id+store) gives the resolver the real
     * product-level discount attributes, same as the PDP teaser sees.
     */
    private function loadFullProduct(\Magento\Quote\Model\Quote\Item $item): ?\Magento\Catalog\Api\Data\ProductInterface
    {
        $itemProduct = $item->getProduct();
        if (!$itemProduct || !$itemProduct->getId()) {
            return null;
        }
        try {
            return $this->productRepository->getById((int) $itemProduct->getId(), false, $item->getStoreId());
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        if (!$this->config->isPricingEnabled()) {
            return;
        }

        $quote = $observer->getEvent()->getData('quote');
        if (!$quote || !$quote->getCustomerId()) {
            return;
        }

        $customerId = (int) $quote->getCustomerId();
        $isActive   = $this->memberAccess->isActiveMember($customerId);

        $membershipSku = $this->config->getMembershipSku();

        foreach ($quote->getAllItems() as $item) {
            // Skip the membership product itself and child/duplicate rows.
            if ($item->getSku() === $membershipSku || $item->getParentItemId()) {
                continue;
            }
            $product = $this->loadFullProduct($item);
            if (!$product) {
                continue;
            }

            if ($isActive) {
                $regular = (float) $product->getPrice();
                $result  = $this->resolver->resolveForProduct($product, $regular);
                if (!$result->hasDiscount()) {
                    if ($item->getCustomPrice() !== null) {
                        $item->setCustomPrice(null);
                        $item->setOriginalCustomPrice(null);
                    }
                    continue;
                }
                $item->setCustomPrice($result->memberPrice);
                $item->setOriginalCustomPrice($result->memberPrice);
                $item->getProduct()->setIsSuperMode(true);
            } elseif ($item->getCustomPrice() !== null) {
                // Member is no longer active but a member price is still frozen on
                // this line (e.g. they lapsed / entered renewal_pending after adding
                // it while active). Clear it so the regular catalog price applies
                // again. Guarded by the resolver so we only undo lines CaliberNation
                // itself would have priced — never a custom price set elsewhere.
                $regular = (float) $product->getPrice();
                if ($this->resolver->resolveForProduct($product, $regular)->hasDiscount()) {
                    $item->setCustomPrice(null);
                    $item->setOriginalCustomPrice(null);
                }
            }
        }
    }
}
