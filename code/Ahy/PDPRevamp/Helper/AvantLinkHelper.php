<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Builds AvantLink click-tracking URLs for sponsored banners.
 *
 * Link anatomy: https://www.avantlink.com/click.php?tt=cl&pw={websiteId}&mi={merchantId}&url={destination}&ctc={customTrackingCode}
 *   tt  - tool type ("cl" = Custom Link, since the banner creative is our own, not an AvantLink-hosted ad)
 *   pw  - AvantLink website ID (the affiliate's registered site)
 *   mi  - AvantLink merchant ID being promoted
 *   url - the destination URL being tracked, url-encoded
 *   ctc - optional custom tracking code, for attributing clicks back to a specific placement
 * Reference: https://www.avantlink.com/api.php?help=1&module=AdSearch
 */
class AvantLinkHelper extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'pdprevamp_avantlink/general/enabled';
    private const XML_PATH_WEBSITE_ID = 'pdprevamp_avantlink/general/website_id';
    private const XML_PATH_DEFAULT_MERCHANT_ID = 'pdprevamp_avantlink/general/default_merchant_id';
    private const XML_PATH_TRACKING_CODE_PREFIX = 'pdprevamp_avantlink/general/custom_tracking_code_prefix';
    private const XML_PATH_BANNER_PLACEMENTS = 'pdprevamp_avantlink/general/banner_placements';

    private const CLICK_BASE_URL = 'https://www.avantlink.com/click.php';

    public function __construct(Context $context, private readonly Json $json)
    {
        parent::__construct($context);
    }

    /**
     * Admin-configured sponsor banner placements (Stores > Configuration > AvantLink Affiliate
     * Tracking > Sponsor Banner Placements), keyed by CMS block identifier so new sponsored
     * banners/advertisers can be added without a code deploy.
     *
     * @return array<string, array{merchantId: ?string, trackingCode: ?string}>
     */
    public function getBannerPlacements(?int $storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_BANNER_PLACEMENTS, ScopeInterface::SCOPE_STORE, $storeId);
        if (!$raw) {
            return [];
        }

        try {
            $rows = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        $placements = [];
        foreach ((array) $rows as $row) {
            $blockId = trim((string) ($row['block_id'] ?? ''));
            if ($blockId === '') {
                continue;
            }

            $placements[$blockId] = [
                'merchantId' => trim((string) ($row['merchant_id'] ?? '')) ?: null,
                'trackingCode' => trim((string) ($row['tracking_code'] ?? '')) ?: null,
            ];
        }

        return $placements;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Wrap a destination URL with an AvantLink click-tracking link.
     *
     * @param string $destinationUrl Absolute URL the shopper should land on (the merchant's page).
     * @param string|null $merchantId Falls back to the configured default merchant ID.
     * @param string|null $placementCode Identifies this specific banner placement, appended after
     *                                   the configured tracking-code prefix, e.g. "pdp-fbt-sponsor".
     * @return string|null Null when tracking is disabled or required IDs are missing - callers
     *                      should fall back to the plain $destinationUrl in that case.
     */
    public function buildClickUrl(
        string $destinationUrl,
        ?string $merchantId = null,
        ?string $placementCode = null,
        ?int $storeId = null
    ): ?string {
        if (!$this->isEnabled($storeId)) {
            return null;
        }

        $websiteId = $this->scopeConfig->getValue(self::XML_PATH_WEBSITE_ID, ScopeInterface::SCOPE_STORE, $storeId);
        $merchantId = $merchantId ?: $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_MERCHANT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!$websiteId || !$merchantId) {
            return null;
        }

        $params = [
            'tt' => 'cl',
            'pw' => $websiteId,
            'mi' => $merchantId,
            'url' => $destinationUrl,
        ];

        $ctc = $this->buildTrackingCode($placementCode, $storeId);
        if ($ctc) {
            $params['ctc'] = $ctc;
        }

        return self::CLICK_BASE_URL . '?' . http_build_query($params);
    }

    private function buildTrackingCode(?string $placementCode, ?int $storeId): ?string
    {
        $prefix = $this->scopeConfig->getValue(self::XML_PATH_TRACKING_CODE_PREFIX, ScopeInterface::SCOPE_STORE, $storeId);
        $parts = array_filter([$prefix, $placementCode]);

        return $parts ? implode('-', $parts) : null;
    }
}
