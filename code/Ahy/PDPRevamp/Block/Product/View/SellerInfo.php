<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

/**
 * PDP "Seller Info" tab (see product/view/details-seller-info.phtml). Only
 * exists to give that template access to the admin-configured per-seller
 * fallback values (Stores > Configuration > General > PDP Seller Info) for
 * the tab's otherwise 100%-automatic stats - see getSellerInfoOverride() for
 * the "only fills in what Webkul doesn't have" matching/fallback contract.
 */
class SellerInfo extends Template
{
    private const CONFIG_PATH = 'pdprevamp_seller_info/general/seller_data';

    private ScopeConfigInterface $scopeConfig;
    private Json $serializer;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        Json $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->scopeConfig = $scopeConfig;
        $this->serializer = $serializer;
    }

    /**
     * Matches case-insensitively against either shop_title or the seller's
     * plain name, the same convention already used for the "Best Seller"
     * badge config (pdprevamp_pdp_badges/adventure_seekers/best_seller_name).
     *
     * @return array{since_year?:string,rating?:string,review_count?:string,response_time?:string,orders_fulfilled?:string}|null
     */
    public function getSellerInfoOverride(string $shopTitle, string $name): ?array
    {
        $shopTitle = trim($shopTitle);
        $name = trim($name);
        if ($shopTitle === '' && $name === '') {
            return null;
        }

        $raw = $this->scopeConfig->getValue(self::CONFIG_PATH, ScopeInterface::SCOPE_STORE);
        if (!$raw) {
            return null;
        }

        try {
            $rows = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $configuredName = trim((string) ($row['seller_name'] ?? ''));
            if ($configuredName === '') {
                continue;
            }
            if (strcasecmp($configuredName, $shopTitle) === 0 || strcasecmp($configuredName, $name) === 0) {
                return $row;
            }
        }

        return null;
    }
}
