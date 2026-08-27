<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use FalcoSense\Search\Helper\Data;
use Psr\Log\LoggerInterface;

/**
 * Fetches a short-lived search token from the platform and caches it locally.
 * The real API key is only ever sent server-side (PHP → platform).
 * The browser receives only the token.
 */
class SearchTokenService
{
    private const CACHE_FILE_PREFIX = '/tmp/smartsearch_token_';
    private const REFRESH_BEFORE   = 300; // re-fetch 5 min before expiry

    public function __construct(
        private readonly Data            $helper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns a valid short-lived token for the given Magento store.
     * Uses file cache — only calls the platform when token is missing or about to expire.
     */
    public function getToken(int $magentoStoreId = 0): string
    {
        return $this->getTokenData($magentoStoreId)['token'];
    }

    /**
     * Same as getToken() but also returns the expiry, so callers that hand the
     * token to the browser can let it proactively refresh before the token
     * actually goes bad instead of only reacting to a 401 from the platform.
     *
     * @return array{token: string, expires_at: ?string}
     */
    public function getTokenData(int $magentoStoreId = 0): array
    {
        $cacheFile = self::CACHE_FILE_PREFIX . $magentoStoreId . '.json';

        // Check file cache
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (!empty($cached['token']) && !empty($cached['expires_at'])) {
                $expiresAt = strtotime($cached['expires_at']);
                if ($expiresAt > time() + self::REFRESH_BEFORE) {
                    return $cached;
                }
            }
        }

        // Fetch a new token from the platform
        $token = $this->fetchFromPlatform($magentoStoreId);
        if ($token !== null) {
            file_put_contents($cacheFile, json_encode($token), LOCK_EX);
            return $token;
        }

        // Fallback: caller should handle an empty token gracefully
        return ['token' => '', 'expires_at' => null];
    }

    private function fetchFromPlatform(int $magentoStoreId): ?array
    {
        $endpointBase = rtrim((string) $this->helper->getEndpointUrl($magentoStoreId), '/');
        $apiKey       = $this->helper->getApiKey($magentoStoreId);

        if (!$endpointBase || !$apiKey) {
            $this->logger->warning('[SmartSearch] SearchTokenService: endpoint or API key not configured.');
            return null;
        }

        // The token endpoint lives at the platform base URL (replace /ingest path if present)
        $tokenUrl = preg_replace('#/api/v1/ingest.*#', '', $endpointBase) . '/api/v1/auth/token';

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode !== 200) {
            $this->logger->error(sprintf(
                '[SmartSearch] SearchTokenService: token fetch failed (HTTP %d, cURL: %s). URL: %s',
                $httpCode, $curlErr, $tokenUrl
            ));
            return null;
        }

        $data = json_decode($raw, true);
        if (empty($data['success']) || empty($data['token'])) {
            $this->logger->error('[SmartSearch] SearchTokenService: invalid token response: ' . substr($raw, 0, 200));
            return null;
        }

        $this->logger->info(sprintf(
            '[SmartSearch] SearchTokenService: new token issued, expires %s.',
            $data['expires_at'] ?? '?'
        ));

        return [
            'token'      => $data['token'],
            'expires_at' => $data['expires_at'],
        ];
    }
}
