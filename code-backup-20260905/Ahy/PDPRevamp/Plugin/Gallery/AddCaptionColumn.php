<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Gallery;

use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\App\ResourceConnection;

class AddCaptionColumn
{
    public const COLUMN = 'ahy_thumb_caption';

    private const TABLE = 'catalog_product_entity_media_gallery';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param Gallery $subject
     * @param array $result rows as core loaded them
     * @return array same rows, each with the caption key added
     */
    public function afterLoadProductGalleryByAttributeId(Gallery $subject, $result)
    {
        if (!is_array($result) || !$result) {
            return $result;
        }

        $valueIds = [];
        foreach ($result as $row) {
            if (isset($row['value_id'])) {
                $valueIds[] = (int) $row['value_id'];
            }
        }

        if (!$valueIds) {
            return $result;
        }

        $captions = $this->fetchCaptions($valueIds);

        foreach ($result as &$row) {
            // Always set the key, even to null: the admin template reads
            // data.ahy_thumb_caption and a missing key would leave the field
            // showing whatever the previously selected image had.
            $row[self::COLUMN] = $captions[(int) ($row['value_id'] ?? 0)] ?? null;
        }
        unset($row);

        return $result;
    }

    /**
     * @param int[] $valueIds
     * @return array<int, string|null> keyed by value_id
     */
    private function fetchCaptions(array $valueIds): array
    {
        $connection = $this->resourceConnection->getConnection();

        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName(self::TABLE),
                    ['value_id', self::COLUMN]
                )
                ->where('value_id IN (?)', array_values(array_unique($valueIds)))
        );

        $captions = [];
        foreach ($rows as $valueId => $caption) {
            $captions[(int) $valueId] = $caption === null || $caption === ''
                ? null
                : (string) $caption;
        }

        return $captions;
    }
}