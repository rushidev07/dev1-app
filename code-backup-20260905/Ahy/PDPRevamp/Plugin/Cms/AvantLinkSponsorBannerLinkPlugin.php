<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Cms;

use Ahy\PDPRevamp\Helper\AvantLinkHelper;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateFbtSponsorBannerBlock;
use Magento\Cms\Block\Block;

/**
 * Rewrites the outbound link inside sponsored CMS banners to route through AvantLink's
 * click-tracking URL, so clicks attribute back to us without admins needing to hand-build
 * AvantLink links when they edit a banner's image/copy in Content > Blocks.
 *
 * Which CMS blocks are tracked - and which AvantLink merchant each one credits - is admin
 * configurable via Stores > Configuration > AvantLink Affiliate Tracking > Sponsor Banner
 * Placements (see {@see AvantLinkHelper::getBannerPlacements()}), so a new sponsored banner or a
 * change of advertiser doesn't need a code deploy.
 *
 * The original PDP "Frequently Bought Together" sponsor banner
 * (CMS block {@see CreateFbtSponsorBannerBlock::BLOCK_IDENTIFIER}) stays tracked against the
 * default merchant even if no config row is added for it, for backward compatibility.
 */
class AvantLinkSponsorBannerLinkPlugin
{
    private const LEGACY_TRACKED_BLOCKS = [
        CreateFbtSponsorBannerBlock::BLOCK_IDENTIFIER => null,
    ];

    public function __construct(
        private readonly AvantLinkHelper $avantLinkHelper
    ) {
    }

    public function afterToHtml(Block $subject, string $result): string
    {
        $blockId = $subject->getBlockId();

        if ($blockId === null || trim($result) === '') {
            return $result;
        }

        $placements = $this->avantLinkHelper->getBannerPlacements();

        if (array_key_exists($blockId, $placements)) {
            return $this->wrapFirstLink(
                $result,
                $placements[$blockId]['merchantId'],
                $placements[$blockId]['trackingCode'] ?? $blockId
            );
        }

        if (array_key_exists($blockId, self::LEGACY_TRACKED_BLOCKS)) {
            return $this->wrapFirstLink($result, self::LEGACY_TRACKED_BLOCKS[$blockId], $blockId);
        }

        return $result;
    }

    /**
     * Rewrites only the first <a href="..."> in the block's HTML - the banner is a single
     * clickable image/link, so there's exactly one destination to wrap.
     */
    private function wrapFirstLink(string $html, ?string $merchantId, string $placementCode): string
    {
        return preg_replace_callback(
            '/(<a\s[^>]*?href=")([^"]+)(")/i',
            function (array $matches) use ($merchantId, $placementCode) {
                $trackedUrl = $this->avantLinkHelper->buildClickUrl(
                    html_entity_decode($matches[2]),
                    $merchantId,
                    $placementCode
                );

                if (!$trackedUrl) {
                    // Tracking disabled/misconfigured - leave the CMS-authored link untouched.
                    return $matches[0];
                }

                return $matches[1] . htmlspecialchars($trackedUrl, ENT_QUOTES) . $matches[3];
            },
            $html,
            1
        );
    }
}
