<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Service\Seller;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * Resolves the real marketplace seller behind a batch of products.
 *
 * Authoritative source is the Webkul pair marketplace_product.mageproduct_id ->
 * seller_id -> marketplace_userdata.shop_title. That is deliberately the SAME
 * seller_id CaliberNation's pricing gate reads (see
 * Ahy\CaliberNation\Model\Service\Pricing\SellerResolver), so what the card says
 * and what the member-price engine acts on can never disagree.
 *
 * Products with no marketplace row fall back to the denormalised shop_title
 * product attribute. Anything still unresolved is simply absent from the result
 * - admin-owned stock has no seller, and the caller renders nothing rather than
 * inventing a name.
 */
class NameResolver
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $collectionFactory
    ) {}

    /**
     * @param int[] $productIds
     * @return array<int,array{seller_id:int|null,name:string}>
     */
    public function resolve(array $productIds): array
    {
        $productIds = array_values(array_filter(array_map('intval', $productIds), static fn(int $id) => $id > 0));
        if (!$productIds) {
            return [];
        }

        $out = $this->fromMarketplace($productIds);

        $missing = array_values(array_diff($productIds, array_keys($out)));
        if ($missing) {
            $out += $this->fromProductAttribute($missing);
        }

        return $out;
    }

    /**
     * @param int[] $productIds
     * @return array<int,array{seller_id:int|null,name:string}>
     */
    private function fromMarketplace(array $productIds): array
    {
        try {
            $conn = $this->resource->getConnection();
            $select = $conn->select()
                ->from(
                    ['mp' => $this->resource->getTableName('marketplace_product')],
                    ['mageproduct_id', 'seller_id']
                )
                ->joinLeft(
                    ['mu' => $this->resource->getTableName('marketplace_userdata')],
                    'mu.seller_id = mp.seller_id',
                    ['shop_title']
                )
                ->where('mp.mageproduct_id IN (?)', $productIds);

            $out = [];
            foreach ($conn->fetchAll($select) as $row) {
                $name = trim((string) $row['shop_title']);
                if ($name === '') {
                    continue;
                }
                $out[(int) $row['mageproduct_id']] = [
                    'seller_id' => (int) $row['seller_id'],
                    'name'      => $name,
                ];
            }
            return $out;
        } catch (\Exception) {
            // Marketplace module/tables absent -> fall through to the attribute.
            return [];
        }
    }

    /**
     * @param int[] $productIds
     * @return array<int,array{seller_id:int|null,name:string}>
     */
    private function fromProductAttribute(array $productIds): array
    {
        try {
            $collection = $this->collectionFactory->create();
            // Same flag the CaliberNation price endpoint sets: tells MSI the stock
            // filter is already handled, avoiding "No linked stock found" locally.
            $collection->setFlag('has_stock_status_filter', true);
            $collection->addAttributeToSelect('shop_title')->addIdFilter($productIds);

            $out = [];
            foreach ($collection as $product) {
                $name = trim((string) $product->getData('shop_title'));
                if ($name === '') {
                    continue;
                }
                $out[(int) $product->getId()] = ['seller_id' => null, 'name' => $name];
            }
            return $out;
        } catch (\Exception) {
            return [];
        }
    }
}
