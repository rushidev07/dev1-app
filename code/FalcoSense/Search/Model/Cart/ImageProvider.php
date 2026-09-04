<?php
namespace FalcoSense\Search\Model\Cart;

use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Checkout\CustomerData\ItemPoolInterface;
use Magento\Checkout\CustomerData\DefaultItem as CustomerDataItem;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Configuration\Item\ItemResolverInterface;

class ImageProvider extends \Magento\Checkout\Model\Cart\ImageProvider
{
    private const CDN_BASE = 'https://d1sq8cqyuyotg2.cloudfront.net/media/catalog/product';

    public function __construct(
        CartItemRepositoryInterface $itemRepository,
        ItemPoolInterface $itemPool,
        CustomerDataItem $customerDataItem = null,
        ImageHelper $imageHelper = null,
        ItemResolverInterface $itemResolver = null
    ) {
        parent::__construct($itemRepository, $itemPool, $customerDataItem, $imageHelper, $itemResolver);
    }

    public function getImages($cartId): array
    {
        $result = [];
        $items  = $this->itemRepository->getList($cartId);

        foreach ($items as $cartItem) {
            $product   = $cartItem->getProduct();
            $imagePath = $product->getData('image') ?? null;

            if ($imagePath && $imagePath !== 'no_selection' && strpos($imagePath, 'placeholder') === false) {
                $result[$cartItem->getItemId()] = [
                    'src'    => self::CDN_BASE . $imagePath,
                    'alt'    => $product->getName(),
                    'width'  => 78,
                    'height' => 78,
                ];
            } else {
                $result[$cartItem->getItemId()] = parent::getImages($cartId)[$cartItem->getItemId()] ?? [];
            }
        }

        return $result;
    }
}
