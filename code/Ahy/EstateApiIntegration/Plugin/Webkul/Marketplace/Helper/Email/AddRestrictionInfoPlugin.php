<?php

declare(strict_types=1);

namespace Ahy\EstateApiIntegration\Plugin\Webkul\Marketplace\Helper\Email;

use Ahy\EstateApiIntegration\Logger\Logger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Webkul\Marketplace\Helper\Email as MpEmailHelper;
use Webkul\Marketplace\Model\SaleslistFactory;

/**
 * Adds Orchid age/restriction and magazine/regulated-weapon compliance-hold info to the
 * Webkul Marketplace seller-notification emails, without modifying the vendor module.
 */
class AddRestrictionInfoPlugin
{
    private const RESTRICTION_MESSAGES = [
        '0' => 'API key fail',
        '3' => 'Roster state',
        '4' => 'Orchid fail',
        '5' => 'UPC not in the Orchid DB',
        'B' => 'Not Restricted product',
    ];

    private const SHIPPING_MESSAGES = [
        'AA'  => 'Ship to Consumer Permitted',
        'BB'  => 'Ship to Consumer Pending License and/or Permit Verification',
        'CC'  => 'Ship to FFL Dealer Only',
        'FF'  => 'Restricted (Product Cannot Be Purchased)',
        'SHI' => 'Ship to Consumer Pending Required Age Verification',
        'SNJ' => 'Shotgun Ammo Ship to Consumer Permitted. Handgun & Rifle Ammo Ship to Consumer Pending License and/or Permit Verification',
        'SCO' => 'Ship to Consumer Permitted (Colorado): carrier must verify recipient was born on or before 1/28/2007 and requires a signature',
    ];

    private const AGE_MESSAGES = [
        'AAA'   => '18+ long gun / 21+ other firearms',
        'BBB'   => '21+ all ammo',
        'FFF'   => 'Restricted',
        'ADE'   => '21+ OR 18+ if purchaser: (a) holds license to carry concealed (b) is US Armed Forces (c) is National Guard (d) is a law enforcement officer',
        'AMD'   => '18+ OR 21+ if the ammo is “solely designed for” either: handguns OR the list of firearms identified at MD Public Safety Article 5-101(r)(2)',
        'ANJ'   => '18+ for shotgun ammo & rifle ammo for which no handgun exists; 21+ for handgun ammo & rifle ammo that may be used in a handgun',
        'ACOCF' => 'Age restriction (Colorado): recipient must be born on or before 1/28/2007',
        'ACORF' => 'Age restriction (Colorado): recipient must be 18 years of age or older',
    ];

    private const RESTRICTED_PRODUCT_TYPES = ['magazine', 'regulated-weapon', 'firearm'];

    private SaleslistFactory $saleslistFactory;
    private OrderItemCollectionFactory $orderItemCollectionFactory;
    private ProductRepositoryInterface $productRepository;
    private Logger $logger;

    public function __construct(
        SaleslistFactory $saleslistFactory,
        OrderItemCollectionFactory $orderItemCollectionFactory,
        ProductRepositoryInterface $productRepository,
        Logger $logger
    ) {
        $this->saleslistFactory = $saleslistFactory;
        $this->orderItemCollectionFactory = $orderItemCollectionFactory;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    public function beforeSendInvoicedOrderEmail(
        MpEmailHelper $subject,
        $emailTemplateVariables,
        $senderInfo,
        $receiverInfo
    ) {
        return [$this->addRestrictionInfo($emailTemplateVariables), $senderInfo, $receiverInfo];
    }

    public function beforeSendPlacedOrderEmail(
        MpEmailHelper $subject,
        $emailTemplateVariables,
        $senderInfo,
        $receiverInfo
    ) {
        return [$this->addRestrictionInfo($emailTemplateVariables), $senderInfo, $receiverInfo];
    }

    private function addRestrictionInfo(array $emailTemplateVariables): array
    {
        $emailTemplateVariables['has_restricted_products'] = false;
        $emailTemplateVariables['restricted_message'] = '';
        $emailTemplateVariables['has_compliance_hold_products'] = false;
        $emailTemplateVariables['compliance_hold_message'] = '';

        $orderId = $emailTemplateVariables['order_id'] ?? null;
        $sellerId = $emailTemplateVariables['seller_id'] ?? null;

        if (!$orderId || !$sellerId) {
            return $emailTemplateVariables;
        }

        try {
            $sellerOrderItemIds = $this->saleslistFactory->create()
                ->getCollection()
                ->addFieldToFilter('order_id', $orderId)
                ->addFieldToFilter('seller_id', $sellerId)
                ->addFieldToSelect('order_item_id')
                ->getColumnValues('order_item_id');

            if (empty($sellerOrderItemIds)) {
                return $emailTemplateVariables;
            }

            [$hasRestrictedProducts, $restrictedMessage] = $this->computeRestrictionInfo($sellerOrderItemIds);
            $emailTemplateVariables['has_restricted_products'] = $hasRestrictedProducts;
            $emailTemplateVariables['restricted_message'] = $restrictedMessage;

            [$hasComplianceHold, $complianceMessage] = $this->computeComplianceHoldInfo($sellerOrderItemIds);
            $emailTemplateVariables['has_compliance_hold_products'] = $hasComplianceHold;
            $emailTemplateVariables['compliance_hold_message'] = $complianceMessage;
        } catch (\Exception $e) {
            $this->logger->error(
                'AddRestrictionInfoPlugin: unable to compute restriction/compliance-hold email info for ' .
                'order_id=' . $orderId . ', seller_id=' . $sellerId . ' — flagging for manual review: ' .
                $e->getMessage()
            );

            // Fail closed: never silently send a compliance email without a hold banner when
            // our detection breaks — force a manual-review flag instead of defaulting to "clear".
            $emailTemplateVariables['has_restricted_products'] = true;
            $emailTemplateVariables['restricted_message'] =
                '<strong>This order could not be automatically verified and requires manual compliance review.</strong>';
        }

        return $emailTemplateVariables;
    }

    private function computeRestrictionInfo(array $sellerOrderItemIds): array
    {
        $hasRestrictedProducts = false;
        $productRestrictions = [];

        $orderItemCollection = $this->orderItemCollectionFactory->create()
            ->addFieldToFilter('item_id', ['in' => $sellerOrderItemIds]);

        foreach ($orderItemCollection as $orderItem) {
            $sku = (string) $orderItem->getSku();
            $name = (string) $orderItem->getName();
            $level = trim((string) $orderItem->getData('orchid_restriction_level'));

            if (!$sku || stripos($sku, 'FREE') === 0) {
                continue;
            }

            if (!isset($productRestrictions[$name])) {
                $productRestrictions[$name] = [];
            }

            $decoded = json_decode($level, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (!empty($decoded['restriction'])) {
                    foreach ((array) $decoded['restriction'] as $code) {
                        $productRestrictions[$name][] =
                            $code . ' - ' . (self::RESTRICTION_MESSAGES[$code] ?? $code);
                    }
                    $hasRestrictedProducts = true;
                }

                if (!empty($decoded['shipping_restriction'])
                    && isset(self::SHIPPING_MESSAGES[$decoded['shipping_restriction']])
                ) {
                    $code = $decoded['shipping_restriction'];
                    $productRestrictions[$name][] = $code . ' - ' . self::SHIPPING_MESSAGES[$code];
                    $hasRestrictedProducts = true;
                }

                if (!empty($decoded['age_restriction'])
                    && isset(self::AGE_MESSAGES[$decoded['age_restriction']])
                ) {
                    $code = $decoded['age_restriction'];
                    $productRestrictions[$name][] = $code . ' - ' . self::AGE_MESSAGES[$code];
                    $hasRestrictedProducts = true;
                }

                continue;
            }

            if ($level !== '' && isset(self::RESTRICTION_MESSAGES[$level])) {
                $productRestrictions[$name][] = $level . ' - ' . self::RESTRICTION_MESSAGES[$level];
                $hasRestrictedProducts = true;
            }
        }

        $restrictedMessage = '';
        if ($hasRestrictedProducts) {
            $output = [];
            foreach ($productRestrictions as $productName => $messages) {
                if (empty($messages)) {
                    continue;
                }
                $output[] = '<strong>' . $productName . '</strong><br/>' . implode('<br/>', array_unique($messages));
            }

            $restrictedMessage =
                '<strong>This order contains restricted item(s) and has been placed on hold for additional ' .
                'verification.</strong><br/><br/>' . implode('<br/><br/>', $output);
        }

        return [$hasRestrictedProducts, $restrictedMessage];
    }

    private function computeComplianceHoldInfo(array $sellerOrderItemIds): array
    {
        $hasComplianceHoldProducts = false;
        $complianceHoldRestrictions = [];

        $complianceItemCollection = $this->orderItemCollectionFactory->create()
            ->addFieldToFilter('item_id', ['in' => $sellerOrderItemIds]);

        foreach ($complianceItemCollection as $orderItem) {
            $sku = (string) $orderItem->getSku();
            $name = (string) $orderItem->getName();

            if (!$sku || stripos($sku, 'FREE') === 0) {
                continue;
            }

            try {
                $product = $this->productRepository->getById((int) $orderItem->getProductId());
                $productType = $product->getAttributeText('producttype');

                if (!$productType) {
                    continue;
                }

                $productType = is_array($productType)
                    ? strtolower(trim(implode(',', $productType)))
                    : strtolower(trim((string) $productType));

                if (in_array($productType, self::RESTRICTED_PRODUCT_TYPES, true)) {
                    if (!isset($complianceHoldRestrictions[$name])) {
                        $complianceHoldRestrictions[$name] = [];
                    }
                    $complianceHoldRestrictions[$name][] =
                        'Compliance Hold - This item is a magazine or regulated weapon and requires verification.';
                    $hasComplianceHoldProducts = true;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        $complianceMessage = '';
        if ($hasComplianceHoldProducts) {
            $productNames = array_keys(array_filter($complianceHoldRestrictions));
            $complianceMessage =
                '<strong style="color:#856404;">This order contains magazine or regulated weapon item(s) and has ' .
                'been placed on compliance hold for verification.</strong><br/><br/>' .
                '<strong style="color: #333;">Restricted Item(s):</strong><br/>' .
                implode('<br/>', $productNames) . '<br/><br/>' .
                '<span style="color:#856404;">Compliance Hold - This item is a magazine or regulated weapon and ' .
                'requires verification.</span>';
        }

        return [$hasComplianceHoldProducts, $complianceMessage];
    }
}
