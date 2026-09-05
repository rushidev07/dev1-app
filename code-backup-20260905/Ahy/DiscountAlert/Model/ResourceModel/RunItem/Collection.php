<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\ResourceModel\RunItem;

use Ahy\DiscountAlert\Api\Data\RunItemInterface;
use Ahy\DiscountAlert\Model\Catalog\ProductAttributeMeta;
use Ahy\DiscountAlert\Model\ResourceModel\RunItem as RunItemResource;
use Ahy\DiscountAlert\Model\RunItem as RunItemModel;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Psr\Log\LoggerInterface;

class Collection extends AbstractCollection
{
    public const FIELD_CURRENT_STATUS = 'current_status';
    public const FIELD_PRODUCT_EXISTS = 'product_exists';

    /**
     * The special price the catalog holds right now, as opposed to the one the run
     * recorded at send time. The two diverge as soon as a product is reverted to MSRP,
     * or when a later import puts the vendor's MAP back.
     */
    public const FIELD_CURRENT_SPECIAL_PRICE = 'current_special_price';

    public const STATUS_FILTER_ENABLED  = 'enabled';
    public const STATUS_FILTER_DISABLED = 'disabled';
    public const STATUS_FILTER_DELETED  = 'deleted';
    public const STATUS_FILTER_ANY      = 'any';

    /** Whether the product still carries a special price on the storefront. */
    public const PRICE_STATE_DISCOUNTED = 'discounted';
    public const PRICE_STATE_MSRP       = 'msrp';
    public const PRICE_STATE_ANY        = 'any';

    /**
     * Default scope: the scope the alert itself is configured and evaluated at.
     */
    private const DEFAULT_STORE_ID = 0;

    private bool $productJoined      = false;
    private bool $statusJoined       = false;
    private bool $specialPriceJoined = false;

    /**
     * Maps unqualified field names onto main_table.
     *
     * catalog_product_entity carries its own sku, and the EAV join brings more columns
     * still, so an unqualified "sku" or "name" in a WHERE or ORDER BY is ambiguous. Magento
     * resolves addFieldToFilter() and setOrder() through this map, so every filter and sort
     * lands on the run item's own column.
     *
     * @var array<string, array<string, string>>
     */
    protected $_map = [
        'fields' => [
            'item_id'        => 'main_table.item_id',
            'run_id'         => 'main_table.run_id',
            'product_id'     => 'main_table.product_id',
            'sku'            => 'main_table.sku',
            'name'           => 'main_table.name',
            'price'          => 'main_table.price',
            'special_price'  => 'main_table.special_price',
            'discount_pct'   => 'main_table.discount_pct',
            'status_at_send' => 'main_table.status_at_send',
            'is_new'         => 'main_table.is_new',
            'disabled_at'    => 'main_table.disabled_at',
            'disabled_by'    => 'main_table.disabled_by',
        ],
    ];

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        private readonly ProductAttributeMeta $attributeMeta,
        private readonly MetadataPool $metadataPool,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    protected function _construct(): void
    {
        $this->_init(RunItemModel::class, RunItemResource::class);
    }

    public function forRun(int $runId): self
    {
        $this->addFieldToFilter(RunItemInterface::RUN_ID, $runId);

        return $this;
    }

    /**
     * Join each product's live status, so the grid can show, sort and filter on what the
     * catalog says right now rather than on the snapshot taken at send time.
     *
     * Products deleted since the alert was sent surface with product_exists = 0.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addCurrentStatus(): self
    {
        if ($this->statusJoined) {
            return $this;
        }

        $this->joinProduct();

        $this->getSelect()->joinLeft(
            ['status_table' => $this->attributeMeta->getBackendTable('status')],
            $this->attributeJoin('status_table', 'status'),
            [self::FIELD_CURRENT_STATUS => 'status_table.value']
        );

        $this->statusJoined = true;

        return $this;
    }

    /**
     * Join the special price the catalog holds now, so the grid can show whether a product
     * is still discounted or is standing on its MSRP alone.
     *
     * Reading this live rather than trusting the run's snapshot matters here: a product
     * reverted to MSRP will have its special price written back by the next vendor import,
     * and only the live value reveals that.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addCurrentSpecialPrice(): self
    {
        if ($this->specialPriceJoined) {
            return $this;
        }

        $this->joinProduct();

        $this->getSelect()->joinLeft(
            ['special_table' => $this->attributeMeta->getBackendTable('special_price')],
            $this->attributeJoin('special_table', 'special_price'),
            [self::FIELD_CURRENT_SPECIAL_PRICE => 'special_table.value']
        );

        $this->specialPriceJoined = true;

        return $this;
    }

    /**
     * @param string $state One of the PRICE_STATE_* values; anything else is ignored.
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addPriceStateFilter(string $state): self
    {
        if ($state !== self::PRICE_STATE_DISCOUNTED && $state !== self::PRICE_STATE_MSRP) {
            return $this;
        }

        $this->addCurrentSpecialPrice();

        if ($state === self::PRICE_STATE_DISCOUNTED) {
            $this->getSelect()->where('special_table.value > 0');
        } else {
            // Null covers a cleared special price; zero covers one written as an empty
            // discount, which the storefront ignores just the same.
            $this->getSelect()->where('special_table.value IS NULL OR special_table.value <= 0');
        }

        return $this;
    }

    /**
     * Join catalog_product_entity once, however many attribute joins hang off it.
     *
     * Products deleted since the alert was sent surface with product_exists = 0.
     */
    private function joinProduct(): void
    {
        if ($this->productJoined) {
            return;
        }

        $this->getSelect()
            ->joinLeft(
                ['product_table' => $this->getTable('catalog_product_entity')],
                'product_table.entity_id = main_table.' . RunItemInterface::PRODUCT_ID,
                []
            )
            ->columns([
                self::FIELD_PRODUCT_EXISTS => new Expression('IF(product_table.entity_id IS NULL, 0, 1)'),
            ]);

        $this->productJoined = true;
    }

    /**
     * ON condition tying an EAV value table to the joined product row at default scope.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function attributeJoin(string $alias, string $attributeCode): string
    {
        $connection = $this->getConnection();
        $linkField  = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        return \implode(' AND ', [
            \sprintf('%s.%s = product_table.%s', $alias, $linkField, $linkField),
            $connection->quoteInto($alias . '.attribute_id = ?', $this->attributeMeta->getId($attributeCode)),
            $connection->quoteInto($alias . '.store_id = ?', self::DEFAULT_STORE_ID),
        ]);
    }

    /**
     * @param string $status One of the STATUS_FILTER_* values; anything else is ignored.
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function addCurrentStatusFilter(string $status): self
    {
        if ($status === self::STATUS_FILTER_ANY) {
            return $this;
        }

        $this->addCurrentStatus();

        switch ($status) {
            case self::STATUS_FILTER_ENABLED:
                $this->getSelect()->where('product_table.entity_id IS NOT NULL')
                    ->where('status_table.value = ?', Status::STATUS_ENABLED);
                break;
            case self::STATUS_FILTER_DISABLED:
                $this->getSelect()->where('product_table.entity_id IS NOT NULL')
                    ->where('status_table.value = ?', Status::STATUS_DISABLED);
                break;
            case self::STATUS_FILTER_DELETED:
                $this->getSelect()->where('product_table.entity_id IS NULL');
                break;
        }

        return $this;
    }

    /**
     * Restrict to products flagged for the first time, or to ones seen before.
     */
    public function addNewFilter(?bool $isNew): self
    {
        if ($isNew !== null) {
            $this->addFieldToFilter(RunItemInterface::IS_NEW, $isNew ? 1 : 0);
        }

        return $this;
    }

    /**
     * Free-text match across SKU and product name.
     */
    public function addKeywordFilter(string $keyword): self
    {
        $keyword = \trim($keyword);

        if ($keyword === '') {
            return $this;
        }

        $this->addFieldToFilter(
            [RunItemInterface::SKU, RunItemInterface::NAME],
            [
                ['like' => '%' . $keyword . '%'],
                ['like' => '%' . $keyword . '%'],
            ]
        );

        return $this;
    }

    /**
     * Match a SKU fragment.
     */
    public function addSkuFilter(string $sku): self
    {
        $sku = \trim($sku);

        if ($sku !== '') {
            $this->addFieldToFilter(RunItemInterface::SKU, ['like' => '%' . $sku . '%']);
        }

        return $this;
    }

    /**
     * Match a product name fragment.
     */
    public function addNameFilter(string $name): self
    {
        $name = \trim($name);

        if ($name !== '') {
            $this->addFieldToFilter(RunItemInterface::NAME, ['like' => '%' . $name . '%']);
        }

        return $this;
    }

    /**
     * Restrict to a discount percentage range. Either bound may be null.
     */
    public function addDiscountRangeFilter(?float $from, ?float $to): self
    {
        if ($from !== null) {
            $this->addFieldToFilter(RunItemInterface::DISCOUNT_PCT, ['gteq' => $from]);
        }

        if ($to !== null) {
            $this->addFieldToFilter(RunItemInterface::DISCOUNT_PCT, ['lteq' => $to]);
        }

        return $this;
    }

    /**
     * Sort by a run item column or by the joined live status.
     */
    public function addSort(string $field, string $direction): self
    {
        $direction = \strtoupper($direction) === self::SORT_ORDER_ASC
            ? self::SORT_ORDER_ASC
            : self::SORT_ORDER_DESC;

        if ($field === self::FIELD_CURRENT_STATUS) {
            $this->addCurrentStatus();
            $this->getSelect()->order(self::FIELD_CURRENT_STATUS . ' ' . $direction);

            return $this;
        }

        // Joined columns are select aliases rather than run item fields, so they are
        // ordered on the select directly instead of through the field map.
        if ($field === self::FIELD_CURRENT_SPECIAL_PRICE) {
            $this->addCurrentSpecialPrice();
            $this->getSelect()->order(self::FIELD_CURRENT_SPECIAL_PRICE . ' ' . $direction);

            return $this;
        }

        $this->setOrder($field, $direction);

        return $this;
    }
}
