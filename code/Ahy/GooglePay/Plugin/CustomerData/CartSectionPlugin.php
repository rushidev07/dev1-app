<?php
declare(strict_types=1);

namespace Ahy\GooglePay\Plugin\CustomerData;

use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Ahy\GooglePay\Model\CartRestrictionChecker;

class CartSectionPlugin
{
    private CheckoutSession $checkoutSession;
    private CartRestrictionChecker $checker;

    public function __construct(
        CheckoutSession $checkoutSession,
        CartRestrictionChecker $checker
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->checker = $checker;
    }

    public function afterGetSectionData(Cart $subject, array $result): array
    {
        $quote = $this->checkoutSession->getQuote();

        $result['cartContainsRestrictedProduct'] =
            $quote && $quote->getId()
                ? $this->checker->cartContainsRestrictedProduct($quote)
                : false;

        if ($quote && $quote->getId()) {
            // Recalculate totals so shipping + tax are included and the value
            // matches the order summary, instead of the last-persisted totals.
            $quote->collectTotals();
            $result['grandTotalAmount'] = (float) $quote->getGrandTotal();
        } else {
            $result['grandTotalAmount'] = 0.0;
        }

        return $result;
    }
}

