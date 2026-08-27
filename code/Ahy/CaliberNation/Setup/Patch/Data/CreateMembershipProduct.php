<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Creates the Caliber Nation membership as a VIRTUAL product with the "None"
 * tax class (no tax) and flags it via is_caliber_nation_membership.
 */
class CreateMembershipProduct implements DataPatchInterface
{
    private const SKU            = 'caliber-nation-annual';
    private const NAME           = 'Caliber Nation Annual Membership';
    private const XML_PRICE      = 'caliber_nation/membership/price';
    private const TAX_CLASS_NONE = 0;

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly State $appState,
        private readonly IndexerRegistry $indexerRegistry
    ) {}

    public function apply(): self
    {
        try {
            $this->productRepository->get(self::SKU);
            return $this; // already exists — idempotent
        } catch (NoSuchEntityException) {
            // create below
        }

        // Product save (URL rewrites / indexers) requires an area code to be set.
        $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, [$this, 'createProduct']);

        return $this;
    }

    /**
     * Runs inside an emulated adminhtml area (callback for emulateAreaCode).
     */
    public function createProduct(): void
    {
        $price = (float) ($this->scopeConfig->getValue(self::XML_PRICE) ?: 99.99);

        $websiteIds = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteIds[] = (int) $website->getId();
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->productFactory->create();
        $product->setSku(self::SKU)
            ->setName(self::NAME)
            ->setTypeId(Type::TYPE_VIRTUAL)
            ->setAttributeSetId($product->getDefaultAttributeSetId())
            ->setStatus(Status::STATUS_ENABLED)
            ->setVisibility(Visibility::VISIBILITY_NOT_VISIBLE)
            ->setPrice($price)
            ->setTaxClassId(self::TAX_CLASS_NONE)
            ->setData(AddMembershipProductAttribute::ATTRIBUTE_CODE, 1)
            ->setWebsiteIds($websiteIds)
            ->setStockData([
                'use_config_manage_stock' => 0,
                'manage_stock'            => 0,
                'is_in_stock'             => 1,
                'qty'                     => 999999,
            ]);

        $product = $this->productRepository->save($product);

        // Index this single product so it is immediately salable even when indexers
        // are in "schedule" mode (otherwise isSalable() is false → cannot add to cart).
        $productId = (int) $product->getId();
        foreach (['cataloginventory_stock', 'catalog_product_price'] as $indexerId) {
            try {
                $this->indexerRegistry->get($indexerId)->reindexRow($productId);
            } catch (\Throwable $e) {
                // non-fatal — a later full reindex will cover it
            }
        }
    }

    public static function getDependencies(): array
    {
        return [AddMembershipProductAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
