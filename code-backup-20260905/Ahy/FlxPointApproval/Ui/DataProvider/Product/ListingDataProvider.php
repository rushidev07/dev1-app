<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Ui\DataProvider\Product;

use Ahy\FlxPointApproval\Model\ResourceModel\Product\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ListingDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        if (!$this->getCollection()->isLoaded()) {
            $this->getCollection()->load();
        }

        $items = [];
        foreach ($this->getCollection() as $item) {
            $data = $item->getData();
            if (!empty($data['import_error'])) {
                $data['import_error'] = '<div style="color:#b30000;font-size:11px;max-width:300px;white-space:pre-wrap;word-break:break-word;">'
                    . nl2br(htmlspecialchars($data['import_error']))
                    . '</div>';
            }
            $items[] = $data;
        }

        // Also include simple variants from flxpoint_approval_variant so both
        // the configurable parent and its children are visible in the grid.
        $connection   = $this->getCollection()->getConnection();
        $variantTable = $this->getCollection()->getResource()->getTable('flxpoint_approval_variant');
        $parentTable  = $this->getCollection()->getResource()->getTable('flxpoint_approval_product');

        $variants = $connection->fetchAll(
            "SELECT v.*, p.status, p.entity_id AS parent_entity_id
             FROM {$variantTable} v
             INNER JOIN {$parentTable} p ON p.sku = v.parent_sku
             WHERE p.product_type = 'configurable'
             ORDER BY v.parent_sku, v.entity_id"
        );

        foreach ($variants as $variant) {
            $items[] = [
                // Offset by 10M to keep variant IDs unique in the grid.
                // View.php resolves these back to the parent record.
                'entity_id'          => 10000000 + (int) $variant['entity_id'],
                'sku'                => $variant['sku'],
                'name'               => $variant['name'],
                'product_type'       => 'simple',
                'attribute_set_code' => $variant['attribute_set_code'],
                'price'              => $variant['price'],
                'qty'                => $variant['qty'],
                'seller_id'          => $variant['seller_id'],
                'status'             => $variant['status'] ?? 'pending',
                'import_error'       => null,
                'created_at'         => $variant['created_at'],
            ];
        }

        return [
            'totalRecords' => count($items),
            'items'        => $items,
        ];
    }
}