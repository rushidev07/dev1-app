<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Slider;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\RegionFactory;

class Products extends Template
{
    private SmartSearchHelper $helper;
    private CustomerSession   $customerSession;
    private RegionFactory     $regionFactory;

    /** Full API response, populated on first call to getSliderProducts(). */
    private ?array $apiResponse = null;

    public function __construct(
        Context           $context,
        SmartSearchHelper $helper,
        CustomerSession   $customerSession,
        RegionFactory     $regionFactory,
        array             $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper          = $helper;
        $this->customerSession = $customerSession;
        $this->regionFactory   = $regionFactory;
    }

    /**
     * The slider slug to fetch. Preference order:
     *   1. Block data attribute `slider_type` (set in CMS block or layout XML)
     *   2. Magento admin config `smart_search/sliders/slider_N_slug` (not used here — layout sets it)
     */
    public function getSliderType(): string
    {
        return (string) ($this->getData('slider_type') ?? 'newest');
    }

    /**
     * Return the full state/region name for the logged-in customer (e.g. "Texas").
     * Returns '' for guests or customers with no address.
     *
     * Address priority: default billing → default shipping → first address.
     * Multiple-address edge case: whichever has a region wins first.
     */
    public function getCustomerGeoState(): string
    {
        try {
            if (!$this->customerSession->isLoggedIn()) {
                return '';
            }

            $customer = $this->customerSession->getCustomer();
            if (!$customer || !$customer->getId()) {
                return '';
            }

            // Build a priority-ordered list of address IDs to try
            $addressIds = array_filter(array_unique([
                (int) $customer->getDefaultBilling(),
                (int) $customer->getDefaultShipping(),
            ]));

            // Fall back to all addresses if default addresses don't yield a region
            $addresses = $customer->getAddresses();
            foreach ($addresses as $addr) {
                $addressIds[] = (int) $addr->getId();
            }

            foreach ($addressIds as $addressId) {
                if ($addressId <= 0) {
                    continue;
                }
                $address = $customer->getAddressById($addressId);
                if (!$address || !$address->getId()) {
                    continue;
                }

                // Try the stored region name first
                $region = trim((string) $address->getRegion());

                // Magento sometimes only stores region_id without the text name —
                // look up the full name from the directory table as a fallback.
                if ($region === '' && $address->getRegionId()) {
                    try {
                        $regionModel = $this->regionFactory->create()->load((int) $address->getRegionId());
                        $region = trim((string) $regionModel->getName());
                    } catch (\Throwable) {
                        // ignore
                    }
                }

                if ($region !== '') {
                    return $region;
                }
            }
        } catch (\Throwable $e) {
            // Never let address lookup break the slider
        }

        return '';
    }

    public function getSliderProducts(): array
    {
        if ($this->apiResponse !== null) {
            return $this->apiResponse['products'] ?? [];
        }

        $type   = $this->getSliderType();
        $apiKey = $this->helper->getApiKey();

        $endpointUrl  = $this->helper->getEndpointUrl();
        $platformBase = rtrim(preg_replace('#/api/v1/ingest/products.*#', '', $endpointUrl), '/');
        if (!$platformBase) {
            $platformBase = 'https://app.falcosense.com';
        }

        $url = $platformBase . '/api/v1/sliders/' . urlencode($type)
             . '?api_key=' . urlencode($apiKey)
             . '&geo_state=' . urlencode($this->getCustomerGeoState());

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

    /**
     * Returns the slider title from the platform API response.
     * Falls back to the slug if the API hasn't been called yet or returned no title.
     */
    public function getSliderTitle(): string
    {
        // Ensure API has been called so we have the title
        if ($this->apiResponse === null) {
            $this->getSliderProducts();
        }
        $title = $this->apiResponse['title'] ?? '';
        return $title !== '' ? strtoupper($title) : strtoupper(str_replace(['_', '-'], ' ', $this->getSliderType()));
    }

    public function getProductUrl(string $urlKey): string
    {
        return $this->getBaseUrl() . $urlKey . '.html';
    }

    public function getCacheLifetime(): int
    {
        return 120;
    }

    public function getCacheKey(): string
    {
        return 'ahy_slider_' . $this->getSliderType() . '_' . md5($this->getCustomerGeoState());
    }

    public function getCacheTags(): array
    {
        return ['ahy_slider', 'ahy_slider_' . $this->getSliderType()];
    }

    public function getCacheKeyInfo(): array
    {
        return ['AHY_SLIDER', $this->getSliderType(), $this->getCustomerGeoState()];
    }
}
