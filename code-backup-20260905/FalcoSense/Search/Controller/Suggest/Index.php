<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Suggest;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use FalcoSense\Search\Service\SearchTokenService;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface   $request,
        private readonly RawFactory         $rawFactory,
        private readonly SmartSearchHelper  $helper,
        private readonly SearchTokenService $tokenService,
    ) {}

    public function execute()
    {
        $q       = (string) $this->request->getParam('q', '');
        $storeId = (int)    $this->helper->getPlatformStoreId();
        $token   = $this->tokenService->getToken($storeId);

        // Derive suggest URL from endpoint base (e.g. https://app-staging.falcosense.com/api/v1/ingest/... -> .../api/v1/suggest)
        $endpoint = $this->helper->getEndpointUrl();
        $parts    = parse_url($endpoint ?: 'http://localhost:8001');
        $base     = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $suggestUrl = $base . '/api/v1/suggest';

        $url = $suggestUrl . '?search_token=' . urlencode($token) . '&q=' . urlencode($q);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/json');
        $result->setHeader('Cache-Control', 'no-store, no-cache');

        if ($body === false || $status < 200 || $status >= 300) {
            $result->setContents('{"terms":[],"products":[],"categories":[],"total":0}');
        } else {
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || isset($decoded[0])) {
                $result->setContents('{"terms":[],"products":[],"categories":[],"total":0}');
            } else {
                $result->setContents($body);
            }
        }

        return $result;
    }
}
