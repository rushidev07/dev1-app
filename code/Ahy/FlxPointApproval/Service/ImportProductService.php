<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Service;

use Ahy\FlxPoint\Helper\CreateSqlFile;
use Ahy\FlxPointApproval\Model\ResourceModel\Product\CollectionFactory as StagedCollectionFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptionsFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Set\CollectionFactory as AttributeSetCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Imports a staged (admin-approved) FlxPoint product.
 *
 * Delegates all SQL generation and execution to Ahy\FlxPoint\Helper\CreateSqlFile so an
 * approved product is created through the exact same code path as a product that skipped
 * the approval hold (e.g. during a full delta resync) — no separate/duplicate import logic.
 */
class ImportProductService
{
    private const APPROVAL_SQL_FOLDER = '/flxPoint/Import-Process/product-catalog/approval/';

    private StagedCollectionFactory $stagedCollectionFactory;
    private ResourceConnection $resourceConnection;
    private CreateSqlFile $createSqlFile;
    private DirectoryList $directoryList;

    public function __construct(
        StagedCollectionFactory $stagedCollectionFactory,
        ProductRepositoryInterface $productRepository,
        ProductFactory $productFactory,
        StockRegistryInterface $stockRegistry,
        AttributeSetCollectionFactory $attributeSetCollectionFactory,
        AttributeRepositoryInterface $attributeRepository,
        CategoryCollectionFactory $categoryCollectionFactory,
        ConfigurableOptionsFactory $configurableOptionsFactory,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager,
        CreateSqlFile $createSqlFile,
        DirectoryList $directoryList
    ) {
        $this->stagedCollectionFactory = $stagedCollectionFactory;
        $this->resourceConnection      = $resourceConnection;
        $this->createSqlFile          = $createSqlFile;
        $this->directoryList          = $directoryList;

        // $productRepository, $productFactory, $stockRegistry, $attributeSetCollectionFactory,
        // $attributeRepository, $categoryCollectionFactory, $configurableOptionsFactory and
        // $storeManager are unused now — kept as constructor arguments to avoid touching the
        // rest of the previously-compiled DI signature.
    }

    /**
     * Import a single staged product by entity_id.
     *
     * Does NOT reindex/flush cache — callers must invoke reindexAndFlushCache() themselves once
     * they're done (a single call after one product, or one call after a whole mass-approve batch)
     * so batch approvals don't pay for a full reindex per product.
     *
     * @return array{success: bool, message: string}
     */
    public function execute(int $entityId): array
    {
        try {
            $collection = $this->stagedCollectionFactory->create();
            $collection->addFieldToFilter('entity_id', $entityId);
            $staged = $collection->getFirstItem();

            if (!$staged->getId()) {
                return ['success' => false, 'message' => "Staged product #{$entityId} not found."];
            }

            $rowData     = $staged->getData();
            $variantRows = [];

            if ($rowData['product_type'] === 'configurable') {
                $connection   = $this->resourceConnection->getConnection();
                $variantTable = $this->resourceConnection->getTableName('flxpoint_approval_variant');
                $variantRows  = $connection->fetchAll(
                    $connection->select()->from($variantTable)->where('parent_sku = ?', $rowData['sku'])
                );

                if (empty($variantRows)) {
                    return ['success' => false, 'message' => 'Configurable product has no variants staged.'];
                }
            }

            $folderPath = $this->directoryList->getPath('var') . self::APPROVAL_SQL_FOLDER;

            return $this->createSqlFile->createAndExecuteApprovalSql($folderPath, $rowData, $variantRows);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Reindex and flush cache — the same two steps `ahy:flxpoint:import-product -i` runs after
     * executing its SQL. Raw SQL writes bypass Magento's model-save events, so nothing else tells
     * the indexers/cache a product changed; the storefront won't show it otherwise. Call this once
     * after execute() for a single approval, or once after a whole mass-approve batch — never per
     * item in a loop, or bulk approvals pay for a full reindex per product.
     *
     * @return array{success: bool, message: string}
     */
    public function reindexAndFlushCache(): array
    {
        return $this->createSqlFile->reindexAndFlushCache();
    }
}
