<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service;

use Ahy\DiscountAlert\Api\ProductCollectorInterface;
use Ahy\DiscountAlert\Model\Catalog\ProductAttributeMeta;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Finds discounted products with a single aggregate query.
 *
 * This deliberately queries the EAV tables directly rather than going through a product
 * collection: the job is a whole-catalog sweep that needs four attributes and no product
 * behaviour, and loading collection models for tens of thousands of rows in cron would
 * cost orders of magnitude more memory. Attribute IDs and value tables are resolved
 * through the EAV API, and the entity link field comes from the metadata pool so the
 * query also holds on installations where Staging rewrites the catalog to row_id.
 *
 * Disabled products are excluded: they are already off the storefront, so they need no
 * alert and would only pad the digest and the review grid.
 */
class DiscountProductCollector implements ProductCollectorInterface
{
    private const ATTR_PRICE         = 'price';
    private const ATTR_SPECIAL_PRICE = 'special_price';
    private const ATTR_NAME          = 'name';
    private const ATTR_STATUS        = 'status';

    /**
     * Attribute values are read at default scope, matching the scope the alert is
     * configured at. Store-level price overrides are intentionally out of scope.
     */
    private const DEFAULT_STORE_ID = 0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductAttributeMeta $attributeMeta,
        private readonly MetadataPool $metadataPool
    ) {}

    public function count(float $threshold): int
    {
        $select = $this->buildSelect($threshold);
        $select->reset(Select::COLUMNS)
            ->reset(Select::ORDER)
            ->columns(new Expression('COUNT(*)'));

        return (int) $this->resource->getConnection()->fetchOne($select);
    }

    public function getList(float $threshold, int $limit = 0): array
    {
        $select = $this->buildSelect($threshold);

        if ($limit > 0) {
            $select->limit($limit);
        }

        return $this->resource->getConnection()->fetchAll($select);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function buildSelect(float $threshold): Select
    {
        $connection = $this->resource->getConnection();
        $linkField  = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $priceTable   = $this->attributeMeta->getBackendTable(self::ATTR_PRICE);
        $specialTable = $this->attributeMeta->getBackendTable(self::ATTR_SPECIAL_PRICE);
        $nameTable    = $this->attributeMeta->getBackendTable(self::ATTR_NAME);
        $statusTable  = $this->attributeMeta->getBackendTable(self::ATTR_STATUS);

        $discountExpression = new Expression('ROUND((p.value - sp.value) / p.value * 100, 2)');

        return $connection->select()
            ->from(
                ['cpe' => $this->resource->getTableName('catalog_product_entity')],
                [
                    self::KEY_PRODUCT_ID => 'entity_id',
                    self::KEY_SKU        => 'sku',
                ]
            )
            ->join(
                ['p' => $priceTable],
                $this->attributeJoin('p', 'cpe', $linkField, self::ATTR_PRICE),
                [self::KEY_PRICE => 'value']
            )
            ->join(
                ['sp' => $specialTable],
                $this->attributeJoin('sp', 'cpe', $linkField, self::ATTR_SPECIAL_PRICE),
                [self::KEY_SPECIAL_PRICE => 'value']
            )
            ->joinLeft(
                ['n' => $nameTable],
                $this->attributeJoin('n', 'cpe', $linkField, self::ATTR_NAME),
                [self::KEY_NAME => 'value']
            )
            ->join(
                ['st' => $statusTable],
                $this->attributeJoin('st', 'cpe', $linkField, self::ATTR_STATUS),
                [self::KEY_STATUS => 'value']
            )
            ->columns([self::KEY_DISCOUNT_PCT => $discountExpression])
            ->where('sp.value > 0')
            ->where('sp.value < p.value')
            // Already-disabled products are not on sale, so they are not worth alerting on.
            ->where('st.value = ?', Status::STATUS_ENABLED)
            ->where('(p.value - sp.value) / p.value > ?', $threshold / 100)
            ->order(self::KEY_DISCOUNT_PCT . ' ' . Select::SQL_DESC);
    }

    /**
     * ON condition tying an EAV value table to the product table at default scope.
     */
    private function attributeJoin(
        string $alias,
        string $entityAlias,
        string $linkField,
        string $attributeCode
    ): string {
        $connection = $this->resource->getConnection();

        return \implode(' AND ', [
            \sprintf('%s.%s = %s.%s', $alias, $linkField, $entityAlias, $linkField),
            $connection->quoteInto(
                $alias . '.attribute_id = ?',
                $this->attributeMeta->getId($attributeCode)
            ),
            $connection->quoteInto($alias . '.store_id = ?', self::DEFAULT_STORE_ID),
        ]);
    }
}
