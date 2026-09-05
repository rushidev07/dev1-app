<?php
declare(strict_types=1);

namespace Ahy\ApplePay\Plugin\CustomerData;

use Magento\Checkout\CustomerData\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\QuoteIdMaskFactory;

class CartSectionPlugin
{
    private CheckoutSession $checkoutSession;
    private QuoteIdMaskFactory $quoteIdMaskFactory;

    public function __construct(
        CheckoutSession $checkoutSession,
        QuoteIdMaskFactory $quoteIdMaskFactory
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
    }

    public function afterGetSectionData(Cart $subject, array $result): array
    {
        $quote = $this->checkoutSession->getQuote();

        if (!$quote || !$quote->getId()) {
            $result['guest_mask_id'] = null;
            return $result;
        }

        if (!$quote->getCustomerId()) {
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($quote->getId(), 'quote_id');
            $result['guest_mask_id'] = $quoteIdMask->getMaskedId() ?: null;
        } else {
            $result['guest_mask_id'] = null;
        }

        return $result;
    }
}
