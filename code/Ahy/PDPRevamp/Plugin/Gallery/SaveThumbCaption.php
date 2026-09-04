<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Gallery;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\CreateHandler;
use Magento\Framework\App\ResourceConnection;

class SaveThumbCaption
{
    private const FIELD = 'ahy_thumb_caption';
    private const TABLE = 'catalog_product_entity_media_gallery';
    private const MAX_LENGTH = 255;

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @param CreateHandler $subject
     * @param Product $result
     * @param Product $product
     * @return Product
     */
    public function afterExecute(CreateHandler $subject, $result, $product)
    {
        if (!$product instanceof Product) {
            return $result;
        }

        $attributeCode = $subject->getAttribute()->getAttributeCode();
        $galleryData = $product->getData($attributeCode);

        if (!is_array($galleryData) || !isset($galleryData['images']) || !is_array($galleryData['images'])) {
            return $result;
        }

        foreach ($galleryData['images'] as $image) {
            if (!is_array($image)
                || !array_key_exists(self::FIELD, $image)
                || !empty($image['removed'])
                || empty($image['value_id'])
            ) {
                continue;
            }

            $this->saveCaption((int) $image['value_id'], (string) $image[self::FIELD]);
        }

        return $result;
    }

    /**
     * Tags are stripped rather than escaped: the storefront renders this through
     * Alpine's x-text (textContent), so markup here could never execute, but
     * storing it would mean the admin sees literal tags echoed back in the field.
     */
    private function saveCaption(int $valueId, string $rawCaption): void
    {
        $caption = trim(strip_tags($rawCaption));
        if ($caption !== '') {
            $caption = mb_substr($caption, 0, self::MAX_LENGTH);
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            [self::FIELD => $caption === '' ? null : $caption],
            ['value_id = ?' => $valueId]
        );
    }
}
