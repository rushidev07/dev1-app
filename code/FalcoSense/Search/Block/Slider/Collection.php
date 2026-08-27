<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Slider;

use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Block for the /api/v1/products/collection endpoint.
 *
 * Configure via layout XML or CMS block:
 *
 *   // Filter by brand:
 *   $block->setData('brand', 'Garmin');
 *
 *   // Filter by category:
 *   $block->setData('category', 'Hunting Gear');
 *
 *   // Filter by attribute:
 *   $block->setData('attribute_name', 'Caliber');
 *   $block->setData('attribute_value', '9mm');
 *
 *   // Sorting:
 *   $block->setData('sort', 'popularity'); // popularity | trending | newest | price_asc | price_desc
 *
 *   // Limit:
 *   $block->setData('limit', 12);
 *
 *   // Slider title:
 *   $block->setData('title', 'Top Garmin Products');
 */
class Collection extends Template
{
    private const PLATFORM_FALLBACK = 'https://app.falcosense.com';

    private SmartSearchHelper $helper;

    /** Cached API response */
    private ?array $apiResponse = null;

    public function __construct(
        Context           $context,
        SmartSearchHelper $helper,
        array             $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper = $helper;
    }

    /**
     * Fetch products from the platform collection endpoint.
     * Returns an empty array on any failure so the block renders nothing.
     */
    public function getCollectionProducts(): array
    {
        if ($this->apiResponse !== null) {
            return $this->apiResponse['products'] ?? [];
        }

        $apiKey      = $this->helper->getApiKey();
        $platformBase = $this->resolvePlatformBase();

        $queryParams = array_filter([
            'api_key'         => $apiKey,
            'brand'           => trim((string) ($this->getData('brand')           ?? '')),
            'category'        => trim((string) ($this->getData('category')        ?? '')),
            'attribute_name'  => trim((string) ($this->getData('attribute_name')  ?? '')),
            'attribute_value' => trim((string) ($this->getData('attribute_value') ?? '')),
            'sort'            => trim((string) ($this->getData('sort')            ?? 'popularity')),
            'limit'           => (int) ($this->getData('limit') ?? 12),
            'price_min'       => $this->getData('price_min') !== null ? (float) $this->getData('price_min') : null,
            'price_max'       => $this->getData('price_max') !== null ? (float) $this->getData('price_max') : null,
        ], fn($v) => $v !== '' && $v !== null);

        $url = $platformBase . '/api/v1/products/collection?' . http_build_query($queryParams);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->apiResponse = [];
            return [];
        }

        $this->apiResponse = json_decode($response, true) ?? [];
        return $this->apiResponse['products'] ?? [];
    }

    public function getCollectionTitle(): string
    {
        $title = trim((string) ($this->getData('title') ?? ''));
        if ($title !== '') return strtoupper($title);

        // Auto-generate a title from the filter config
        if ($this->getData('brand')) return strtoupper($this->getData('brand') . ' Products');
        if ($this->getData('category')) return strtoupper($this->getData('category'));
        if ($this->getData('attribute_value')) return strtoupper($this->getData('attribute_value') . ' Products');

        return match ($this->getData('sort') ?? 'popularity') {
            'trending' => 'TRENDING NOW',
            'newest'   => 'NEW ARRIVALS',
            default    => 'FEATURED PRODUCTS',
        };
    }

    public function getProductUrl(string $urlKey): string
    {
        return $this->getBaseUrl() . $urlKey . '.html';
    }

    public function getCacheLifetime(): int { return 120; }

    public function getCacheKey(): string
    {
        return 'ahy_collection_' . md5(implode('|', [
            $this->getData('brand')           ?? '',
            $this->getData('category')        ?? '',
            $this->getData('attribute_name')  ?? '',
            $this->getData('attribute_value') ?? '',
            $this->getData('sort')            ?? '',
            $this->getData('limit')           ?? '',
        ]));
    }

    public function getCacheTags(): array
    {
        return ['ahy_collection'];
    }

    public function getCacheKeyInfo(): array
    {
        return ['AHY_COLLECTION', $this->getCacheKey()];
    }

    private function resolvePlatformBase(): string
    {
        $endpointUrl = $this->helper->getEndpointUrl();
        $base = rtrim(preg_replace('#/api/v1/ingest/products.*#', '', $endpointUrl), '/');
        return $base ?: self::PLATFORM_FALLBACK;
    }
}
