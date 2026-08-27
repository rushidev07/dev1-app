<?php
namespace Ahy\CatalogPerfFix\Plugin\Catalog\Model;

use Psr\Log\LoggerInterface;

class Collection
{
    protected $helper;
    protected $associatesFactory;
    protected $storeManager;
    protected $resource;
    protected $request;
    protected $logger;

    public function __construct(
        \Webkul\MpAssignProduct\Helper\Data $helper,
        \Webkul\MpAssignProduct\Model\AssociatesFactory $associatesFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\App\RequestInterface $request,
        LoggerInterface $logger
    ) {
        $this->helper = $helper;
        $this->associatesFactory = $associatesFactory;
        $this->storeManager = $storeManager;
        $this->resource = $resource;
        $this->request = $request;
        $this->logger = $logger;
    }

    public function afterLoadProductCount(
        \Magento\Catalog\Model\ResourceModel\Category\Collection $subject,
        $items,
        $countRegular,
        $countAnchor,
        $result
    ) {
        $startTime = microtime(true);
        $actionName = $this->request->getFullActionName();

        $assignProductsIds = $this->helper->getCollection()->getAllIds();
        $associateProductIds = $this->associatesFactory->create()->getCollection()->getAllIds();
        $assignProductsIds = array_unique(array_merge($assignProductsIds, $associateProductIds));

        if (empty($assignProductsIds) || $actionName == 'marketplace_seller_collection') {
            $this->logger->info(sprintf(
                '[Ahy_CatalogPerfFix] skipped action=%s assignedIds=%d',
                $actionName,
                count($assignProductsIds)
            ));
            return $result;
        }

        $connection = $this->resource->getConnection();
        $anchor = [];
        $regular = [];
        foreach ($items as $item) {
            if ($item->getIsAnchor()) {
                $anchor[$item->getId()] = $item;
            } else {
                $regular[$item->getId()] = $item;
            }
        }

        $this->logger->info(sprintf(
            '[Ahy_CatalogPerfFix] start action=%s items=%d anchor=%d regular=%d assignedIds=%d',
            $actionName,
            count($items),
            count($anchor),
            count($regular),
            count($assignProductsIds)
        ));

        $websiteId = $this->storeManager->getStore(\Magento\Store\Model\Store::DEFAULT_STORE_ID)->getWebsiteId();
        $categoryTable = $this->resource->getTableName('catalog_category_product');
        $categoryWebsiteTable = $this->resource->getTableName('catalog_product_website');

        // Regular categories: one batched query (same logic as Webkul's original)
        if ($countRegular && !empty($regular)) {
            $regularIds = array_keys($regular);
            $select = $connection->select()
                ->from(['main_table' => $categoryTable], ['category_id', new \Zend_Db_Expr('COUNT(main_table.product_id)')])
                ->where('main_table.category_id IN (?)', $regularIds)
                ->where('main_table.product_id NOT IN (?)', $assignProductsIds)
                ->group('main_table.category_id');
            if ($websiteId) {
                $select->join(['w' => $categoryWebsiteTable], 'main_table.product_id = w.product_id', [])
                    ->where('w.website_id = ?', $websiteId);
            }
            $counts = $connection->fetchPairs($select);
            foreach ($regular as $item) {
                $item->setProductCount($counts[$item->getId()] ?? 0);
            }
        }

        // Anchor categories: ONE query total instead of one query per anchor (was 1,740 queries)
        if ($countAnchor && !empty($anchor)) {
            $pathById = [];
            foreach ($items as $item) {
                $pathById[$item->getId()] = $item->getPath();
            }

            $anchorDescendants = [];
            $allInvolvedIds = [];
            foreach ($anchor as $anchorId => $anchorItem) {
                if (!$anchorItem->getAllChildren()) {
                    $anchorDescendants[$anchorId] = [];
                    continue;
                }
                $anchorPath = $anchorItem->getPath();
                $descendantIds = [(int)$anchorId];
                foreach ($pathById as $id => $path) {
                    if ($id != $anchorId && strpos($path, $anchorPath . '/') === 0) {
                        $descendantIds[] = (int)$id;
                    }
                }
                $anchorDescendants[$anchorId] = $descendantIds;
                $allInvolvedIds = array_merge($allInvolvedIds, $descendantIds);
            }
            $allInvolvedIds = array_unique($allInvolvedIds);

            $productsByCategory = [];
            if (!empty($allInvolvedIds)) {
                $select = $connection->select()
                    ->from(['main_table' => $categoryTable], ['category_id', 'product_id'])
                    ->where('main_table.category_id IN (?)', $allInvolvedIds)
                    ->where('main_table.product_id NOT IN (?)', $assignProductsIds);
                if ($websiteId) {
                    $select->join(['w' => $categoryWebsiteTable], 'main_table.product_id = w.product_id', [])
                        ->where('w.website_id = ?', $websiteId);
                }
                foreach ($connection->fetchAll($select) as $row) {
                    $productsByCategory[$row['category_id']][] = $row['product_id'];
                }
            }

            foreach ($anchor as $anchorId => $anchorItem) {
                if (empty($anchorDescendants[$anchorId])) {
                    $anchorItem->setProductCount(0);
                    continue;
                }
                $productIds = [];
                foreach ($anchorDescendants[$anchorId] as $catId) {
                    if (!empty($productsByCategory[$catId])) {
                        $productIds = array_merge($productIds, $productsByCategory[$catId]);
                    }
                }
                $anchorItem->setProductCount(count(array_unique($productIds)));
            }
        }

        $elapsed = microtime(true) - $startTime;
        $this->logger->info(sprintf(
            '[Ahy_CatalogPerfFix] completed action=%s elapsed=%.3fs anchor=%d regular=%d',
            $actionName,
            $elapsed,
            count($anchor),
            count($regular)
        ));

        return $this;
    }
}
