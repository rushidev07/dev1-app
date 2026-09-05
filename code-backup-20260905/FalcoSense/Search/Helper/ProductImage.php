<?php
namespace FalcoSense\Search\Helper;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\UrlInterface;

class ProductImage
{
    private ResourceConnection $resource;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ResourceConnection $resource,
        StoreManagerInterface $storeManager
    ) {
        $this->resource     = $resource;
        $this->storeManager = $storeManager;
    }

    public function getUrlBySku(string $sku): ?string
    {
        $path = $this->getPathBySku($sku);
        if ($path === null) {
            return null;
        }

        $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return $mediaUrl . 'catalog/product' . $path;
    }

    /**
     * Raw `image` attribute value for a SKU (e.g. "/l/t/filename.jpg") —
     * relative to pub/media/catalog/product, exactly as Magento stores it.
     * Returns null if unset or the "no_selection" placeholder.
     */
    public function getPathBySku(string $sku): ?string
    {
        $path = $this->fetchImagePaths([$sku])[$sku] ?? null;
        return $path;
    }

    /**
     * Batch lookup — one query for many SKUs. Returns [sku => path], only
     * for SKUs that actually have an image set (missing/no_selection are
     * simply absent from the result, never present with a null value).
     *
     * @param string[] $skus
     * @return array<string, string>
     */
    public function fetchImagePaths(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        $conn = $this->resource->getConnection();

        $select = $conn->select()
            ->from(['p' => $conn->getTableName('catalog_product_entity')], ['sku'])
            ->join(
                ['a' => $conn->getTableName('eav_attribute')],
                "a.entity_type_id = 4 AND a.attribute_code = 'image'",
                []
            )
            ->join(
                ['v' => $conn->getTableName('catalog_product_entity_varchar')],
                'v.attribute_id = a.attribute_id AND v.entity_id = p.entity_id',
                ['value']
            )
            ->where('p.sku IN (?)', $skus);

        $result = [];
        foreach ($conn->fetchAll($select) as $row) {
            $value = $row['value'] ?? null;
            if ($value && $value !== 'no_selection') {
                $result[$row['sku']] = $value;
            }
        }

        return $result;
    }
}
