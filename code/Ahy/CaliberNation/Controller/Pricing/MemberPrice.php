<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Pricing;

use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MemberAccess;
use Ahy\CaliberNation\Model\Service\Pricing\MemberPriceResolver;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * AJAX endpoint: returns CaliberNation member prices for a batch of product IDs.
 *
 * GET caliber-nation/pricing/memberprice?ids=123,456,789
 *
 * Used by the Klevu PLP JS injection (js_additional.phtml) to fetch member
 * prices after Klevu renders product cards. Also accepts ids=0 as a lightweight
 * probe to get is_member status without loading any products.
 */
class MemberPrice implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface       $request,
        private readonly JsonFactory            $jsonFactory,
        private readonly Config                 $config,
        private readonly MemberPriceResolver    $resolver,
        private readonly MemberAccess           $memberAccess,
        private readonly CustomerSession        $customerSession,
        private readonly CollectionFactory      $collectionFactory,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {}

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);

        if (!$this->config->isEnabled() || !$this->config->isPricingEnabled()) {
            return $result->setData(['pricing_enabled' => false, 'prices' => []]);
        }

        $isMember = $this->memberAccess->isActiveMember(
            (int) $this->customerSession->getCustomerId()
        );

        // Parse and sanitise IDs — strip zeros (used for member-status probe)
        $idsParam = (string) $this->request->getParam('ids', '');
        $ids = array_values(
            array_filter(
                array_unique(array_map('intval', explode(',', $idsParam))),
                fn(int $id) => $id > 0
            )
        );
        $ids = array_slice($ids, 0, 50); // cap per request

        if (empty($ids)) {
            return $result->setData([
                'pricing_enabled' => true,
                'is_member'       => $isMember,
                'prices'          => [],
            ]);
        }

        $collection = $this->collectionFactory->create();
        // Flag tells MSI/CatalogInventory plugins the stock filter is already handled,
        // preventing the "No linked stock found" exception on this local dump.
        $collection->setFlag('has_stock_status_filter', true);
        $collection
            ->addAttributeToSelect(['price', 'caliber_member_discount_enabled', 'caliber_member_discount_type', 'caliber_member_discount_value'])
            ->addIdFilter($ids);

        $prices = [];
        foreach ($collection as $product) {
            // Configurable products return price=0 from collection — use min child price
            $regularPrice = (float) $product->getPrice();
            if ($regularPrice <= 0 && $product->getTypeId() === 'configurable') {
                $children = $product->getTypeInstance()->getUsedProducts($product);
                $childPrices = array_filter(array_map(fn($c) => (float) $c->getPrice(), $children));
                $regularPrice = $childPrices ? min($childPrices) : 0.0;
            }

            $priceResult = $this->resolver->resolveForProduct($product, $regularPrice ?: null);
            if (!$priceResult->hasDiscount()) {
                continue;
            }
            $savings = $priceResult->regularPrice - $priceResult->memberPrice;
            $prices[(int) $product->getId()] = [
                'hasDiscount'      => true,
                'memberPrice'      => $priceResult->memberPrice,
                'regularPrice'     => $priceResult->regularPrice,
                'formattedMember'  => $this->priceCurrency->format($priceResult->memberPrice, false),
                'formattedRegular' => $this->priceCurrency->format($priceResult->regularPrice, false),
                'formattedSavings' => $this->priceCurrency->format($savings, false),
            ];
        }

        return $result->setData([
            'pricing_enabled' => true,
            'is_member'       => $isMember,
            'prices'          => $prices,
        ]);
    }
}
