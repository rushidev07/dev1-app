<?php
declare(strict_types=1);

namespace Ahy\ApplePay\Plugin\Checkout\Model;

use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Psr\Log\LoggerInterface;

class ShippingInformationManagementPlugin
{
    private CartRepositoryInterface $quoteRepository;
    private LoggerInterface $logger;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        LoggerInterface $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->logger = $logger;
    }

    /**
     * Before plugin for saveAddressInformation
     *
     * @param ShippingInformationManagement $subject
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return array
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        // Load quote
        $quote = $this->quoteRepository->getActive($cartId);

        $shippingAddress = $addressInformation->getShippingAddress();
        $carrierCode = $addressInformation->getShippingCarrierCode();
        $methodCode = $addressInformation->getShippingMethodCode();

        if ($shippingAddress) {
            // Apply your shipping method hack
            $shippingAddress->setShippingMethod($carrierCode . '_' . $methodCode);
            $quote->setShippingAddress($shippingAddress);
        }

        // Return original parameters
        return [$cartId, $addressInformation];
    }
}
