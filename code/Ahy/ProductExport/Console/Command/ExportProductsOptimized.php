<?php

namespace Ahy\ProductExport\Console\Command;

use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Psr\Log\LoggerInterface;

class ExportProductsOptimized extends Command
{
    private State $state;
    private CollectionFactory $productCollectionFactory;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    public function __construct(
        State $state,
        CollectionFactory $productCollectionFactory,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->state                    = $state;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->storeManager             = $storeManager;
        $this->logger                   = $logger;
        parent::__construct();
    }

    protected function configure()
    {
       $this->setName('catalog:export:products-optimized')
            ->setDescription('Export full product catalog to a SQL dump file');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode('frontend');
        } catch (\Exception $e) {
            // Area code already set — safe to ignore
        }

        // ── Output file ───────────────────────────────────────────────────────
        $filePath = BP . '/var/export/product_catalog.sql';
        if (!is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0775, true);
        }

        $fp = fopen($filePath, 'w');
        if (!$fp) {
            $output->writeln('<error>Cannot open export file for writing.</error>');
            return Command::FAILURE;
        }

        // ── Store base URLs ───────────────────────────────────────────────────
        $store        = $this->storeManager->getStore();
        $baseUrl      = rtrim($store->getBaseUrl(), '/');
        $mediaBaseUrl = rtrim($store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');

        // ── SQL header ────────────────────────────────────────────────────────
        fwrite($fp, "-- ============================================================\n");
        fwrite($fp, "--  Magento Product Catalog Export\n");
        fwrite($fp, "--  Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fp, "--  Store: {$baseUrl}\n");
        fwrite($fp, "-- ============================================================\n\n");

        fwrite($fp, "SET NAMES utf8mb4;\n");
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        // ── CREATE TABLE ──────────────────────────────────────────────────────
        fwrite($fp, "DROP TABLE IF EXISTS `exported_product_catalog`;\n\n");
        fwrite($fp, <<<SQL
CREATE TABLE `exported_product_catalog` (
  `id`                  INT UNSIGNED     NOT NULL,
  `sku`                 VARCHAR(255)     NOT NULL,
  `type`                VARCHAR(64)      NOT NULL,
  `attribute_set_id`    SMALLINT         NOT NULL,
  `name`                VARCHAR(512)     DEFAULT NULL,
  `description`         LONGTEXT         DEFAULT NULL,
  `short_description`   TEXT             DEFAULT NULL,
  `price`               DECIMAL(12,4)    DEFAULT NULL,
  `special_price`       DECIMAL(12,4)    DEFAULT NULL,
  `cost`                DECIMAL(12,4)    DEFAULT NULL,
  `status`              TINYINT          DEFAULT NULL COMMENT '1=Enabled 2=Disabled',
  `visibility`          TINYINT          DEFAULT NULL COMMENT '1=Not Visible 2=Catalog 3=Search 4=Catalog+Search',
  `tax_class_id`        SMALLINT         DEFAULT NULL,
  `weight`              DECIMAL(12,4)    DEFAULT NULL,
  `qty`                 DECIMAL(12,4)    DEFAULT NULL,
  `is_in_stock`         TINYINT(1)       DEFAULT NULL,
  `manage_stock`        TINYINT(1)       DEFAULT NULL,
  `min_qty`             DECIMAL(12,4)    DEFAULT NULL,
  `max_sale_qty`        DECIMAL(12,4)    DEFAULT NULL,
  `manufacturer`        VARCHAR(512)     DEFAULT NULL,
  `color`               VARCHAR(255)     DEFAULT NULL,
  `size`                VARCHAR(255)     DEFAULT NULL,
  `meta_title`          VARCHAR(512)     DEFAULT NULL,
  `meta_description`    TEXT             DEFAULT NULL,
  `url_key`             VARCHAR(512)     DEFAULT NULL,
  `shop_url`            TEXT             DEFAULT NULL,
  `image_path`          VARCHAR(512)     DEFAULT NULL,
  `image_url`           TEXT             DEFAULT NULL,
  `thumbnail_path`      VARCHAR(512)     DEFAULT NULL,
  `thumbnail_url`       TEXT             DEFAULT NULL,
  `small_image_path`    VARCHAR(512)     DEFAULT NULL,
  `small_image_url`     TEXT             DEFAULT NULL,
  `media_gallery`       JSON             DEFAULT NULL COMMENT 'All gallery images as JSON',
  `created_at`          DATETIME         DEFAULT NULL,
  `updated_at`          DATETIME         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sku`         (`sku`(191)),
  KEY `idx_status`      (`status`),
  KEY `idx_visibility`  (`visibility`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Magento catalog export — generated {$baseUrl}';\n\n
SQL);

        // ── Batch export ──────────────────────────────────────────────────────
        $pageSize      = 500;
        $totalExported = 0;

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([
            'name', 'description', 'short_description',
            'price', 'special_price', 'cost',
            'status', 'visibility', 'tax_class_id', 'weight',
            'manufacturer', 'color', 'size',
            'meta_title', 'meta_description', 'url_key',
            'image', 'thumbnail', 'small_image',
            'attribute_set_id', 'type_id', 'created_at', 'updated_at',
        ]);

        // Stock data
        $collection->joinTable(
            'cataloginventory_stock_item',
            'product_id=entity_id',
            [
                'qty'          => 'qty',
                'is_in_stock'  => 'is_in_stock',
                'manage_stock' => 'manage_stock',
                'min_qty'      => 'min_qty',
                'max_sale_qty' => 'max_sale_qty',
            ],
            null,
            'left'
        );

        $collection->setPageSize($pageSize);

        $lastPage   = $collection->getLastPageNumber();
        $totalCount = $collection->getSize();

        $output->writeln("<info>Total products found: {$totalCount}</info>");
        $output->writeln('<info>Starting SQL export…</info>');

        for ($currentPage = 1; $currentPage <= $lastPage; $currentPage++) {
            $collection->setCurPage($currentPage);
            $collection->load();

            if (!$collection->count()) {
                break;
            }

            // One INSERT block per page (multi-row for efficiency)
            fwrite($fp, "INSERT INTO `exported_product_catalog` VALUES\n");
            $rowBuffer = [];

            foreach ($collection as $product) {
                try {
                    // ── Media gallery ─────────────────────────────────────
                    $mediaGalleryJson = null;
                    $galleryEntries   = $product->getMediaGalleryEntries();
                    if ($galleryEntries) {
                        $galleryData = [];
                        foreach ($galleryEntries as $entry) {
                            $filePath_         = $entry->getFile();
                            $galleryData[] = [
                                'file'       => $filePath_,
                                'url'        => $mediaBaseUrl . '/catalog/product' . $filePath_,
                                'media_type' => $entry->getMediaType(),
                                'label'      => $entry->getLabel(),
                                'position'   => (int) $entry->getPosition(),
                                'disabled'   => (bool) $entry->getDisabled(),
                            ];
                        }
                        $mediaGalleryJson = json_encode($galleryData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }

                    // ── Image paths & URLs ────────────────────────────────
                    $imagePath     = $product->getImage();
                    $imageUrl      = ($imagePath && $imagePath !== 'no_selection')
                                     ? $mediaBaseUrl . '/catalog/product' . $imagePath
                                     : null;

                    $thumbPath     = $product->getThumbnail();
                    $thumbUrl      = ($thumbPath && $thumbPath !== 'no_selection')
                                     ? $mediaBaseUrl . '/catalog/product' . $thumbPath
                                     : null;

                    $smallPath     = $product->getSmallImage();
                    $smallUrl      = ($smallPath && $smallPath !== 'no_selection')
                                     ? $mediaBaseUrl . '/catalog/product' . $smallPath
                                     : null;

                    // ── Shop (product) URL ────────────────────────────────
                    $shopUrl = null;
                    try {
                        $shopUrl = $product->getProductUrl();
                    } catch (\Throwable $e) {
                        $urlKey  = $product->getUrlKey();
                        $shopUrl = $urlKey ? $baseUrl . '/' . $urlKey . '.html' : null;
                    }

                    // ── Build VALUES row ──────────────────────────────────
                    $rowBuffer[] = $this->buildValuesRow($product, [
                        'image_path'       => $imagePath,
                        'image_url'        => $imageUrl,
                        'thumbnail_path'   => $thumbPath,
                        'thumbnail_url'    => $thumbUrl,
                        'small_image_path' => $smallPath,
                        'small_image_url'  => $smallUrl,
                        'shop_url'         => $shopUrl,
                        'media_gallery'    => $mediaGalleryJson,
                    ]);

                    $totalExported++;

                } catch (\Throwable $e) {
                    $this->logger->error("Skipped product ID {$product->getId()}: " . $e->getMessage());
                }
            }

            fwrite($fp, implode(",\n", $rowBuffer) . ";\n\n");

            $output->writeln("  Page {$currentPage}/{$lastPage} — exported {$totalExported}/{$totalCount}");

            $collection->clear();
            unset($rowBuffer);
        }

        // ── SQL footer ────────────────────────────────────────────────────────
        fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($fp, "-- Export complete. Total rows: {$totalExported}\n");
        fclose($fp);

        $output->writeln("\n<info>✔ SQL export complete!</info>");
        $output->writeln("<info>  Total products : {$totalExported}</info>");
        $output->writeln("<info>  File           : " . BP . "/var/export/product_catalog.sql</info>");

        return Command::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a single SQL VALUES(...) string for one product row.
     */
    private function buildValuesRow($product, array $extra): string
    {
        $cols = [
            (int)   $product->getId(),
            $this->q($product->getSku()),
            $this->q($product->getTypeId()),
            (int)   $product->getAttributeSetId(),
            $this->q($product->getName()),
            $this->q($product->getDescription()),
            $this->q($product->getShortDescription()),
            $this->qFloat($product->getPrice()),
            $this->qFloat($product->getSpecialPrice()),
            $this->qFloat($product->getCost()),
            $this->qInt($product->getStatus()),
            $this->qInt($product->getVisibility()),
            $this->qInt($product->getTaxClassId()),
            $this->qFloat($product->getWeight()),
            $this->qFloat($product->getQty()),
            $this->qInt($product->getIsInStock()),
            $this->qInt($product->getManageStock()),
            $this->qFloat($product->getMinQty()),
            $this->qFloat($product->getMaxSaleQty()),
            $this->q($product->getManufacturer() ? $product->getAttributeText('manufacturer') : null),
            $this->q($product->getColor()        ? $product->getAttributeText('color')        : null),
            $this->q($product->getSize()         ? $product->getAttributeText('size')         : null),
            $this->q($product->getMetaTitle()),
            $this->q($product->getMetaDescription()),
            $this->q($product->getUrlKey()),
            $this->q($extra['shop_url']),
            $this->q($extra['image_path']),
            $this->q($extra['image_url']),
            $this->q($extra['thumbnail_path']),
            $this->q($extra['thumbnail_url']),
            $this->q($extra['small_image_path']),
            $this->q($extra['small_image_url']),
            $extra['media_gallery'] !== null ? $this->q($extra['media_gallery']) : 'NULL',
            $this->q($product->getCreatedAt()),
            $this->q($product->getUpdatedAt()),
        ];

        return '(' . implode(', ', $cols) . ')';
    }

    /** Escape and quote a string value; returns NULL for null/empty. */
    private function q(?string $value): string
    {
        if ($value === null || $value === '') {
            return 'NULL';
        }
        // Escape special characters for SQL string literals
        $escaped = str_replace(
            ['\\', "'", "\0", "\n", "\r", "\x1a"],
            ['\\\\', "''", '\\0', '\\n', '\\r', '\\Z'],
            $value
        );
        return "'{$escaped}'";
    }

    /** Return a quoted DECIMAL or NULL. */
    private function qFloat($value): string
    {
        return ($value !== null && $value !== '') ? (string)(float)$value : 'NULL';
    }

    /** Return a quoted INT or NULL. */
    private function qInt($value): string
    {
        return ($value !== null && $value !== '') ? (string)(int)$value : 'NULL';
    }
}