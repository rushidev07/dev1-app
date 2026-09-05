<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Plugin\Checkout;

use Ahy\CaliberNation\Api\QuoteItemMemberPriceInterface;
use Magento\Checkout\CustomerData\AbstractItem;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;

/**
 * Exposes CaliberNation member pricing on each minicart line so the cart drawer
 * can strike through the pre-membership price beside the member price.
 *
 * Presentation only. Every rule about WHETHER a line has a member price — program
 * switches, active-member check, membership product, child rows, price basis —
 * lives in CaliberNation behind QuoteItemMemberPriceInterface. This plugin asks
 * that one question and copies the answer into the section payload; it never
 * reimplements the policy, so the drawer can never show a number the quote
 * disagrees with.
 *
 * Declared on AbstractItem so every item renderer inherits it (default,
 * configurable, grouped, bundle).
 *
 * The cart section is per-customer private content and is never full-page
 * cached, so member-specific figures are safe to put in it.
 */
class MinicartMemberPrice
{
    public function __construct(
        private readonly QuoteItemMemberPriceInterface $memberPrice,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function afterGetItemData(AbstractItem $subject, array $result, Item $item): array
    {
        $result['caliber_has_member_price'] = false;

        // A price teaser must never be able to break the cart section: if this
        // throws, /customer/section/load 500s and the minicart loses its data.
        try {
            $price = $this->memberPrice->getForQuoteItem($item);
            if ($price === null) {
                return $result;
            }

            $result['caliber_has_member_price']    = true;
            $result['caliber_regular_price_value'] = $price->getRegularPrice();
            $result['caliber_member_price_value']  = $price->getMemberPrice();
        } catch (\Throwable $e) {
            $this->logger->error('[Ahy_PlpRevamp] Minicart member price failed: ' . $e->getMessage());
        }

        return $result;
    }
}
