<?php
declare(strict_types=1);

namespace FalcoSense\Search\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Data extends AbstractHelper
{
    // Products priced above this are excluded from sync — placeholder/test pricing
    // (e.g. 99999) has repeatedly polluted the platform index and required manual
    // cleanup. Shared by the realtime observer path and the cron/full-sync paths
    // so the cap is enforced consistently everywhere a product can reach the platform.
    public const MAX_SYNC_PRICE = 90000.0;

    private const XML_PATH_FRONTEND_ENABLED  = 'smart_search/general/frontend_enabled';
    private const XML_PATH_ENABLED           = 'smart_search/general/enabled';
    private const XML_PATH_REALTIME_ENABLED  = 'smart_search/general/realtime_sync_enabled';
    private const XML_PATH_ENDPOINT_URL      = 'smart_search/general/endpoint_url';
    private const XML_PATH_SEARCH_URL        = 'smart_search/general/search_url';
    private const XML_PATH_PRODUCTS_PER_PAGE = 'smart_search/general/products_per_page';
    private const XML_PATH_API_KEY           = 'smart_search/general/api_key';
    private const XML_PATH_PLATFORM_STORE    = 'smart_search/general/platform_store_id';
    private const XML_PATH_WEBHOOK_SECRET    = 'smart_search/webhook/secret';
    private const XML_PATH_LAST_SYNC_AT      = 'smart_search/cron/last_sync_at';
    private const XML_PATH_FULL_SYNC_FLAG    = 'smart_search/cron/full_sync_requested';

    private const XML_PATH_IMAGE_COMPRESS_ENABLED     = 'smart_search/image_compress/enabled';
    private const XML_PATH_IMAGE_COMPRESS_LAST_RUN_AT = 'smart_search/image_compress/last_run_at';
    private const XML_PATH_IMAGE_COMPRESS_BATCH_SIZE  = 'smart_search/image_compress/batch_size';

    private const XML_PATH_SLIDER_1 = 'smart_search/sliders/slider_1_slug';
    private const XML_PATH_SLIDER_2 = 'smart_search/sliders/slider_2_slug';
    private const XML_PATH_SLIDER_3 = 'smart_search/sliders/slider_3_slug';
    private const XML_PATH_SLIDER_4 = 'smart_search/sliders/slider_4_slug';

    private const XML_PATH_NR_ENABLED       = 'smart_search/no_results_modal/enabled';
    private const XML_PATH_NR_TITLE         = 'smart_search/no_results_modal/title';
    private const XML_PATH_NR_SUBTITLE      = 'smart_search/no_results_modal/subtitle';
    private const XML_PATH_NR_HEADING       = 'smart_search/no_results_modal/section_heading';
    private const XML_PATH_NR_PRODUCT_COUNT = 'smart_search/no_results_modal/product_count';

    private ScopeConfigInterface $config;
    private WriterInterface $configWriter;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->config       = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->storeManager = $storeManager;
    }

    public function isFrontendEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_FRONTEND_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isRealtimeSyncEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_REALTIME_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getEndpointUrl(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSearchUrl(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_SEARCH_URL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getProductsPerPage(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PRODUCTS_PER_PAGE, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? $val : 12;
    }

    public function getEventsEndpointUrl(int|string|null $storeId = null): string
    {
        return $this->buildPlatformUrl('/api/v1/events', $storeId);
    }

    /**
     * Rebuilds the configured endpoint URL's scheme/host/port with a different path.
     * Returns '' if no endpoint is configured.
     */
    public function buildPlatformUrl(string $path, int|string|null $storeId = null): string
    {
        $endpoint = $this->getEndpointUrl($storeId);
        if (!$endpoint) {
            return '';
        }
        $parts = parse_url($endpoint);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . $path;
    }

    public function getApiKey(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getPlatformStoreId(int|string|null $storeId = null): int
    {
        // Dynamic calculation matching FourSeasons approach
        $stores   = $this->storeManager->getStores(false);
        $storeIds = array_keys($stores);
        sort($storeIds);

        if ($storeId === null) {
            $storeId = (int) $this->storeManager->getStore()->getId();
        }

        $position = array_search((int) $storeId, $storeIds);
        if ($position !== false) {
            return (int) $position + 1;
        }

        // Fallback to config value
        return (int) ($this->config->getValue(self::XML_PATH_PLATFORM_STORE, ScopeInterface::SCOPE_STORE, $storeId) ?: 1);
    }

    public function getWebhookSecret(): string
    {
        return (string) $this->config->getValue(self::XML_PATH_WEBHOOK_SECRET, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function getLastSyncAt(): ?string
    {
        $value = $this->config->getValue(self::XML_PATH_LAST_SYNC_AT, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return ($value && $value !== '') ? (string) $value : null;
    }

    public function setLastSyncAt(string $datetime): void
    {
        $this->configWriter->save(self::XML_PATH_LAST_SYNC_AT, $datetime, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function isFullSyncRequested(): bool
    {
        return (bool) $this->config->getValue(self::XML_PATH_FULL_SYNC_FLAG, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function requestFullSync(): void
    {
        $this->configWriter->save(self::XML_PATH_FULL_SYNC_FLAG, 1, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function clearFullSyncFlag(): void
    {
        $this->configWriter->save(self::XML_PATH_FULL_SYNC_FLAG, 0, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function isImageCompressEnabled(): bool
    {
        return (bool) $this->config->getValue(self::XML_PATH_IMAGE_COMPRESS_ENABLED, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /** Delta cursor — the newest `updated_at` seen by the last successful run, or null before the first run. */
    public function getImageCompressLastRunAt(): ?string
    {
        $value = $this->config->getValue(self::XML_PATH_IMAGE_COMPRESS_LAST_RUN_AT, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return ($value && $value !== '') ? (string) $value : null;
    }

    public function setImageCompressLastRunAt(string $datetime): void
    {
        $this->configWriter->save(self::XML_PATH_IMAGE_COMPRESS_LAST_RUN_AT, $datetime, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function getImageCompressBatchSize(): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_IMAGE_COMPRESS_BATCH_SIZE, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return $val > 0 ? $val : 200;
    }

    public function isNoResultsModalEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_NR_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalTitle(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_TITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalSubtitle(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_SUBTITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalHeading(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_HEADING, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsProductCount(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_NR_PRODUCT_COUNT, ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, min(12, $val ?: 6));
    }

    /**
     * Returns all configured slider slugs (1-4), skipping empty ones.
     *
     * @return string[]
     */
    public function getSliderSlugs(int|string|null $storeId = null): array
    {
        $paths  = [self::XML_PATH_SLIDER_1, self::XML_PATH_SLIDER_2, self::XML_PATH_SLIDER_3, self::XML_PATH_SLIDER_4];
        $result = [];
        foreach ($paths as $path) {
            $v = trim((string) $this->config->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
            if ($v !== '') {
                $result[] = $v;
            }
        }
        return $result;
    }
}
