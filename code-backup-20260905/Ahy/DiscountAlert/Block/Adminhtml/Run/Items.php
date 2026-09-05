<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Block\Adminhtml\Run;

use Ahy\DiscountAlert\Api\Data\RunInterface;
use Ahy\DiscountAlert\Api\Data\RunItemInterface;
use Ahy\DiscountAlert\Api\RunRepositoryInterface;
use Ahy\DiscountAlert\Model\ResourceModel\RunItem\Collection as RunItemCollection;
use Ahy\DiscountAlert\Model\ResourceModel\RunItem\CollectionFactory as RunItemCollectionFactory;
use Ahy\DiscountAlert\Model\RunItem;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Grid of the products recorded against one alert run.
 *
 * Sorting, filtering and paging are resolved server side, and every generated URL carries
 * the run ID and token, because the emailed link is the entry point and both values have
 * to survive each interaction. Nothing here needs JavaScript: the filter panel opens by
 * URL and both disable actions are real form submits.
 */
class Items extends Template
{
    /** Request field carrying the checkbox selection. */
    public const FIELD_PRODUCT_IDS = 'product_ids';

    /** Request field carrying a single-row disable, set by that row's submit button. */
    public const FIELD_SINGLE_PRODUCT_ID = 'single_product_id';

    public const PARAM_PAGE          = 'page';
    public const PARAM_LIMIT         = 'limit';
    public const PARAM_SORT          = 'sort';
    public const PARAM_DIR           = 'dir';
    public const PARAM_KEYWORD       = 'keyword';
    public const PARAM_SKU           = 'sku';
    public const PARAM_NAME          = 'name';
    public const PARAM_STATUS        = 'status';
    public const PARAM_PRICE_STATE   = 'price_state';
    public const PARAM_DISCOUNT_FROM = 'discount_from';
    public const PARAM_DISCOUNT_TO   = 'discount_to';
    public const PARAM_NEW           = 'new_only';
    public const PARAM_SHOW_FILTERS  = 'show_filters';

    /** Filter params, in the order the panel and the chip list present them. */
    public const FILTER_PARAMS = [
        self::PARAM_KEYWORD,
        self::PARAM_SKU,
        self::PARAM_NAME,
        self::PARAM_STATUS,
        self::PARAM_PRICE_STATE,
        self::PARAM_DISCOUNT_FROM,
        self::PARAM_DISCOUNT_TO,
        self::PARAM_NEW,
    ];

    private const DEFAULT_LIMIT = 50;
    private const PAGE_SIZES    = [20, 50, 100, 200];

    private const DEFAULT_STATUS = RunItemCollection::STATUS_FILTER_ENABLED;

    /**
     * Every price state shows by default: hiding already-reverted products would make the
     * action look like it had done nothing.
     */
    private const DEFAULT_PRICE_STATE = RunItemCollection::PRICE_STATE_ANY;

    private const DEFAULT_SORT = RunItemInterface::DISCOUNT_PCT;
    private const DEFAULT_DIR  = 'desc';

    /** Columns the grid may be sorted by, mapped to their heading. */
    private const SORTABLE_COLUMNS = [
        RunItemInterface::SKU                   => 'SKU',
        RunItemInterface::NAME                  => 'Product',
        RunItemInterface::PRICE                 => 'Price',
        RunItemInterface::SPECIAL_PRICE         => 'Special',
        RunItemCollection::FIELD_CURRENT_SPECIAL_PRICE => 'Live Special',
        RunItemInterface::DISCOUNT_PCT          => 'Discount',
        RunItemInterface::IS_NEW                => 'New',
        RunItemCollection::FIELD_CURRENT_STATUS => 'Current Status',
    ];

    protected $_template = 'Ahy_DiscountAlert::run/items.phtml';

    private ?RunItemCollection $collection = null;

    public function __construct(
        Context $context,
        private readonly RunRepositoryInterface $runRepository,
        private readonly RunItemCollectionFactory $collectionFactory,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    // ── Run ──────────────────────────────────────────────────────────────────

    public function getRunId(): int
    {
        return (int) $this->getRequest()->getParam('run_id');
    }

    public function getToken(): string
    {
        return (string) $this->getRequest()->getParam('token');
    }

    /**
     * The controller has already validated the token; this reloads the run for display.
     */
    public function getRun(): ?RunInterface
    {
        try {
            return $this->runRepository->getById($this->getRunId());
        } catch (\Throwable) {
            return null;
        }
    }

    public function formatThreshold(RunInterface $run): string
    {
        return $this->trimZeros($run->getThreshold());
    }

    // ── Grid state ───────────────────────────────────────────────────────────

    public function getCurrentPage(): int
    {
        return \max(1, (int) $this->getRequest()->getParam(self::PARAM_PAGE, 1));
    }

    public function getPageSize(): int
    {
        $limit = (int) $this->getRequest()->getParam(self::PARAM_LIMIT, self::DEFAULT_LIMIT);

        return \in_array($limit, self::PAGE_SIZES, true) ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * @return int[]
     */
    public function getPageSizes(): array
    {
        return self::PAGE_SIZES;
    }

    public function getSortField(): string
    {
        $sort = (string) $this->getRequest()->getParam(self::PARAM_SORT, self::DEFAULT_SORT);

        return isset(self::SORTABLE_COLUMNS[$sort]) ? $sort : self::DEFAULT_SORT;
    }

    public function getSortDirection(): string
    {
        return \strtolower((string) $this->getRequest()->getParam(self::PARAM_DIR, self::DEFAULT_DIR)) === 'asc'
            ? 'asc'
            : 'desc';
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    public function getKeywordFilter(): string
    {
        return \trim((string) $this->getRequest()->getParam(self::PARAM_KEYWORD, ''));
    }

    public function getSkuFilter(): string
    {
        return \trim((string) $this->getRequest()->getParam(self::PARAM_SKU, ''));
    }

    public function getNameFilter(): string
    {
        return \trim((string) $this->getRequest()->getParam(self::PARAM_NAME, ''));
    }

    /**
     * Defaults to Enabled: disabled products need no action, so they are hidden until
     * explicitly asked for.
     */
    public function getStatusFilter(): string
    {
        $status = (string) $this->getRequest()->getParam(self::PARAM_STATUS, self::DEFAULT_STATUS);

        return \array_key_exists($status, $this->getStatusOptions()) ? $status : self::DEFAULT_STATUS;
    }

    public function getPriceStateFilter(): string
    {
        $state = (string) $this->getRequest()->getParam(self::PARAM_PRICE_STATE, self::DEFAULT_PRICE_STATE);

        return \array_key_exists($state, $this->getPriceStateOptions()) ? $state : self::DEFAULT_PRICE_STATE;
    }

    /**
     * @return array<string, string> Filter value to label.
     */
    public function getPriceStateOptions(): array
    {
        return [
            RunItemCollection::PRICE_STATE_ANY        => (string) __('Any price'),
            RunItemCollection::PRICE_STATE_DISCOUNTED => (string) __('Still discounted'),
            RunItemCollection::PRICE_STATE_MSRP       => (string) __('On MSRP'),
        ];
    }

    /**
     * Null when both new and previously seen products should show.
     */
    public function getNewFilter(): ?bool
    {
        $value = $this->getFilterValue(self::PARAM_NEW);

        if ($value === '') {
            return null;
        }

        return $value === '1';
    }

    /**
     * @return array<string, string>
     */
    public function getNewOptions(): array
    {
        return [
            ''  => (string) __('New and existing'),
            '1' => (string) __('New in this run'),
            '0' => (string) __('Seen in the previous run'),
        ];
    }

    public function getDiscountFromFilter(): ?float
    {
        return $this->readNumeric(self::PARAM_DISCOUNT_FROM);
    }

    public function getDiscountToFilter(): ?float
    {
        return $this->readNumeric(self::PARAM_DISCOUNT_TO);
    }

    /**
     * Raw request value for a filter field, for repopulating the panel.
     */
    public function getFilterValue(string $param): string
    {
        return \trim((string) $this->getRequest()->getParam($param, ''));
    }

    /**
     * @return array<string, string> Filter value to label.
     */
    public function getStatusOptions(): array
    {
        return [
            RunItemCollection::STATUS_FILTER_ENABLED  => (string) __('Enabled'),
            RunItemCollection::STATUS_FILTER_DISABLED => (string) __('Disabled'),
            RunItemCollection::STATUS_FILTER_DELETED  => (string) __('Deleted'),
            RunItemCollection::STATUS_FILTER_ANY      => (string) __('Any status'),
        ];
    }

    public function hasActiveFilters(): bool
    {
        return $this->getActiveFilters() !== [];
    }

    /**
     * Applied filters as chips, each with a URL that clears just that one.
     *
     * @return array<int, array{label: string, value: string, removeUrl: string}>
     */
    public function getActiveFilters(): array
    {
        $labels = [
            self::PARAM_KEYWORD       => (string) __('Keyword'),
            self::PARAM_SKU           => (string) __('SKU'),
            self::PARAM_NAME          => (string) __('Product'),
            self::PARAM_STATUS        => (string) __('Status'),
            self::PARAM_PRICE_STATE   => (string) __('Price'),
            self::PARAM_DISCOUNT_FROM => (string) __('Discount from'),
            self::PARAM_DISCOUNT_TO   => (string) __('Discount to'),
            self::PARAM_NEW           => (string) __('Products'),
        ];

        $chips = [];

        foreach (self::FILTER_PARAMS as $param) {
            $value = $this->getFilterValue($param);

            if ($value === '') {
                continue;
            }

            if ($param === self::PARAM_STATUS) {
                $options = $this->getStatusOptions();

                // The default status is not a filter the user chose, so it gets no chip.
                if (!isset($options[$value]) || $value === self::DEFAULT_STATUS) {
                    continue;
                }

                $value = $options[$value];
            }

            if ($param === self::PARAM_PRICE_STATE) {
                $options = $this->getPriceStateOptions();

                // The default is not a filter the user chose, so it gets no chip.
                if (!isset($options[$value]) || $value === self::DEFAULT_PRICE_STATE) {
                    continue;
                }

                $value = $options[$value];
            }

            if ($param === self::PARAM_NEW) {
                $value = $this->getNewOptions()[$value] ?? $value;
            }

            $chips[] = [
                'label'     => $labels[$param],
                'value'     => $value,
                'removeUrl' => $this->getGridUrl([$param => null, self::PARAM_PAGE => 1]),
            ];
        }

        return $chips;
    }

    /**
     * The panel is open when asked for by URL, or whenever a filter is applied, so the
     * user can always see and change what is filtering the grid without JavaScript.
     */
    /**
     * Open unless the user has explicitly collapsed it, so the filters are there to hand.
     */
    public function isFilterPanelOpen(): bool
    {
        $requested = $this->getRequest()->getParam(self::PARAM_SHOW_FILTERS);

        if ($requested !== null && $requested !== '') {
            return (bool) $requested;
        }

        return true;
    }

    public function getFilterToggleUrl(): string
    {
        return $this->getGridUrl([self::PARAM_SHOW_FILTERS => $this->isFilterPanelOpen() ? '0' : '1']);
    }

    // ── Data ─────────────────────────────────────────────────────────────────

    /**
     * @return RunItem[]
     */
    public function getItems(): array
    {
        return \array_values($this->getCollection()->getItems());
    }

    public function getTotalItems(): int
    {
        return (int) $this->getCollection()->getSize();
    }

    public function getTotalPages(): int
    {
        return \max(1, (int) $this->getCollection()->getLastPageNumber());
    }

    /**
     * Rows on this page that can still be disabled.
     */
    public function getActionableCount(): int
    {
        $count = 0;

        foreach ($this->getItems() as $item) {
            if ($this->isActionable($item)) {
                $count++;
            }
        }

        return $count;
    }

    public function isActionable(RunItem $item): bool
    {
        return $this->productExists($item)
            && (int) $item->getData(RunItemCollection::FIELD_CURRENT_STATUS) !== Status::STATUS_DISABLED;
    }

    /**
     * Rows on this page that still carry a special price, and so can be reverted to MSRP.
     */
    public function getRevertableCount(): int
    {
        return $this->countWhere(fn (RunItem $item): bool => $this->canRevert($item));
    }

    /**
     * Rows on this page that are on MSRP and have a recorded special price to put back.
     */
    public function getRestorableCount(): int
    {
        return $this->countWhere(fn (RunItem $item): bool => $this->canRestore($item));
    }

    /**
     * A product can be reverted while it still has a live special price. The product must
     * exist; its enabled/disabled status is irrelevant, since a disabled product can still
     * be priced correctly ahead of being re-enabled.
     */
    public function canRevert(RunItem $item): bool
    {
        return $this->productExists($item) && $this->getCurrentSpecialPrice($item) > 0;
    }

    /**
     * A restore needs somewhere to restore from: the run's snapshot must hold a usable
     * special price, and the product must not already be carrying one.
     */
    public function canRestore(RunItem $item): bool
    {
        return $this->productExists($item)
            && $this->getCurrentSpecialPrice($item) <= 0
            && $item->getSpecialPrice() > 0;
    }

    /**
     * The special price the catalog holds right now, or 0.0 when there is none.
     */
    public function getCurrentSpecialPrice(RunItem $item): float
    {
        $value = $item->getData(RunItemCollection::FIELD_CURRENT_SPECIAL_PRICE);

        return $value === null ? 0.0 : (float) $value;
    }

    /**
     * What the storefront is showing for this product right now.
     */
    public function getPriceStateLabel(RunItem $item): string
    {
        if (!$this->productExists($item)) {
            return (string) __('n/a');
        }

        return $this->getCurrentSpecialPrice($item) > 0
            ? $this->formatPrice($this->getCurrentSpecialPrice($item))
            : (string) __('MSRP only');
    }

    public function getPriceStateClass(RunItem $item): string
    {
        if (!$this->productExists($item)) {
            return 'grid-severity-minor';
        }

        return $this->getCurrentSpecialPrice($item) > 0
            ? 'grid-severity-critical'
            : 'grid-severity-notice';
    }

    /**
     * Set once a product has been reverted from this run's page and not restored since.
     */
    public function wasReverted(RunItem $item): bool
    {
        return $item->getRevertedAt() !== null;
    }

    public function getStatusLabel(RunItem $item): string
    {
        if (!$this->productExists($item)) {
            return (string) __('Deleted');
        }

        return (int) $item->getData(RunItemCollection::FIELD_CURRENT_STATUS) === Status::STATUS_DISABLED
            ? (string) __('Disabled')
            : (string) __('Enabled');
    }

    /**
     * Severity class for the status cell, matching admin grid conventions.
     */
    public function getStatusClass(RunItem $item): string
    {
        if (!$this->productExists($item)) {
            return 'grid-severity-minor';
        }

        return (int) $item->getData(RunItemCollection::FIELD_CURRENT_STATUS) === Status::STATUS_DISABLED
            ? 'grid-severity-critical'
            : 'grid-severity-notice';
    }

    public function getNewLabel(RunItem $item): string
    {
        return $item->isNew() ? (string) __('New') : (string) __('Existing');
    }

    public function getNewClass(RunItem $item): string
    {
        return $item->isNew() ? 'grid-severity-major' : 'grid-severity-minor';
    }

    public function formatPrice(float $value): string
    {
        return (string) $this->priceCurrency->format($value, false);
    }

    public function formatPercent(float $value): string
    {
        return \number_format($value, 2) . '%';
    }

    // ── Columns ──────────────────────────────────────────────────────────────

    /**
     * Every column is left aligned, matching the core admin grids: the sales grid left
     * aligns Grand Total too.
     *
     * @return array<int, array{field: string, label: string}>
     */
    public function getColumns(): array
    {
        $columns = [];

        foreach (self::SORTABLE_COLUMNS as $field => $label) {
            $columns[] = [
                'field' => $field,
                'label' => (string) __($label),
            ];
        }

        return $columns;
    }

    public function isSortedBy(string $field): bool
    {
        return $this->getSortField() === $field;
    }

    /**
     * Clicking a heading sorts by it, or flips direction when it is already the sort key.
     */
    public function getSortUrl(string $field): string
    {
        $direction = $this->isSortedBy($field) && $this->getSortDirection() === 'asc' ? 'desc' : 'asc';

        return $this->getGridUrl([
            self::PARAM_SORT => $field,
            self::PARAM_DIR  => $direction,
            self::PARAM_PAGE => 1,
        ]);
    }

    // ── URLs ─────────────────────────────────────────────────────────────────

    /**
     * Grid URL carrying the current state. An override set to null drops that parameter.
     *
     * @param array<string, mixed> $overrides
     */
    public function getGridUrl(array $overrides = []): string
    {
        $params = $overrides + $this->getStateParams();

        return $this->getUrl('*/*/view', \array_filter(
            $params,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
    }

    public function getPageUrl(int $page): string
    {
        return $this->getGridUrl([self::PARAM_PAGE => $page]);
    }

    /**
     * The runs listing, reachable from the Ahy menu.
     */
    public function getListingUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    public function getResetUrl(): string
    {
        return $this->getUrl('*/*/view', $this->getRunParams());
    }

    public function getDisableUrl(): string
    {
        return $this->getUrl('*/*/disable', $this->getStateParams());
    }

    public function getRevertToMsrpUrl(): string
    {
        return $this->getUrl('*/*/revertToMsrp', $this->getStateParams());
    }

    public function getRestoreSpecialPriceUrl(): string
    {
        return $this->getUrl('*/*/restoreSpecialPrice', $this->getStateParams());
    }

    public function getProductEditUrl(int $productId): string
    {
        return $this->getUrl('catalog/product/edit', ['id' => $productId]);
    }

    /**
     * Run identity plus the full grid state, so redirects and form posts return to the
     * same page, sort order and filters.
     *
     * @return array<string, mixed>
     */
    public function getStateParams(): array
    {
        $params = $this->getRunParams() + [
            self::PARAM_PAGE  => $this->getCurrentPage(),
            self::PARAM_LIMIT => $this->getPageSize(),
            self::PARAM_SORT  => $this->getSortField(),
            self::PARAM_DIR   => $this->getSortDirection(),
        ];

        $params[self::PARAM_STATUS]      = $this->getStatusFilter();
        $params[self::PARAM_PRICE_STATE] = $this->getPriceStateFilter();

        foreach (self::FILTER_PARAMS as $param) {
            $value = $this->getFilterValue($param);

            if ($value !== '') {
                $params[$param] = $value;
            }
        }

        return $params;
    }

    /**
     * Hidden inputs that carry the grid state through a GET form.
     *
     * @return array<string, string>
     */
    public function getStateInputs(array $except = []): array
    {
        $inputs = [];

        foreach ($this->getStateParams() as $name => $value) {
            if (!\in_array($name, $except, true)) {
                $inputs[(string) $name] = (string) $value;
            }
        }

        return $inputs;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRunParams(): array
    {
        return ['run_id' => $this->getRunId(), 'token' => $this->getToken()];
    }

    /**
     * @param callable(RunItem): bool $predicate
     */
    private function countWhere(callable $predicate): int
    {
        $count = 0;

        foreach ($this->getItems() as $item) {
            if ($predicate($item)) {
                $count++;
            }
        }

        return $count;
    }

    private function productExists(RunItem $item): bool
    {
        return (int) $item->getData(RunItemCollection::FIELD_PRODUCT_EXISTS) === 1;
    }

    private function readNumeric(string $param): ?float
    {
        $value = $this->getFilterValue($param);

        return \is_numeric($value) ? (float) $value : null;
    }

    private function trimZeros(float $value): string
    {
        return \rtrim(\rtrim(\number_format($value, 2, '.', ''), '0'), '.');
    }

    private function getCollection(): RunItemCollection
    {
        if ($this->collection === null) {
            /** @var RunItemCollection $collection */
            $collection = $this->collectionFactory->create();
            $collection->forRun($this->getRunId())
                ->addCurrentStatus()
                ->addCurrentSpecialPrice()
                ->addCurrentStatusFilter($this->getStatusFilter())
                ->addPriceStateFilter($this->getPriceStateFilter())
                ->addNewFilter($this->getNewFilter())
                ->addKeywordFilter($this->getKeywordFilter())
                ->addSkuFilter($this->getSkuFilter())
                ->addNameFilter($this->getNameFilter())
                ->addDiscountRangeFilter($this->getDiscountFromFilter(), $this->getDiscountToFilter())
                ->addSort($this->getSortField(), $this->getSortDirection())
                ->setPageSize($this->getPageSize())
                ->setCurPage($this->getCurrentPage());

            $this->collection = $collection;
        }

        return $this->collection;
    }
}
