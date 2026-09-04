<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for ahy_pdprevamp_variant_color: the admin-set exact swatch hex
 * color per simple product variant, keyed by product_id. One row per
 * simple product that has had a color explicitly set in the
 * Configurations grid's "Colour" column; absence of a row means no
 * color was set for that variant.
 */
class VariantColor
{
    private const TABLE = 'ahy_pdprevamp_variant_color';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function getHexByProductId(int $productId): ?string
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['hex'])
            ->where('product_id = ?', $productId);

        $hex = $connection->fetchOne($select);
        return $hex !== false ? (string) $hex : null;
    }

    /**
     * @param int[] $productIds
     * @return array<int, string> Map of product_id => hex, only for ids that have a row.
     */
    public function getHexByProductIds(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['product_id', 'hex'])
            ->where('product_id IN (?)', $productIds);

        return $connection->fetchPairs($select);
    }

    public function saveHex(int $productId, string $hex): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['product_id' => $productId, 'hex' => $hex],
            ['hex']
        );
    }

    public function deleteHex(int $productId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            ['product_id = ?' => $productId]
        );
    }
}
