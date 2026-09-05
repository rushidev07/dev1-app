<?php
declare(strict_types=1);

namespace Ahy\ApplePay\Plugin\Checkout\Model;

use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;

class PaymentInformationManagementPlugin
{
    protected $logger;
    protected $cartRepository;

    public function __construct(
        LoggerInterface $logger,
        CartRepositoryInterface $cartRepository
    ) {
        $this->logger = $logger;
        $this->cartRepository = $cartRepository;
    }

    public function beforeSavePaymentInformation(
        \Magento\Checkout\Model\PaymentInformationManagement $subject,
        $cartId,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $quote = $this->cartRepository->getActive($cartId);
        $shippingAddress = $quote->getShippingAddress();

        if ($paymentMethod->getMethod() === 'braintree_applepay' && $shippingAddress) {
            $additionalData = $paymentMethod->getAdditionalData();
            if (is_array($additionalData) && isset($additionalData['applepay_shipping_method'])) {
                $applePayShippingMethod = $additionalData['applepay_shipping_method'];

                if (is_array($applePayShippingMethod)) {
                    $applePayShippingMethod = reset($applePayShippingMethod);
                }

                if ($applePayShippingMethod) {
                    $shippingAddress->setShippingMethod($applePayShippingMethod);
                }
            }
        }

        return [$cartId, $paymentMethod, $billingAddress];
    }
}
