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
    private const REFRESH_BEFORE = 300; // re-fetch 5 min before expiry

    /**
     * Was /tmp — shared, world-visible OS temp space that every process on the
     * host can read and that survives until the OS decides to clean /tmp, not
     * scoped to this install. Magento's own var/ directory is already expected
     * to be private to the deploy/web-server user, so the cache lives there
     * instead, in its own subdirectory (not var/cache/, which bin/magento
     * cache:flush would wipe and cause a burst of token re-fetches).
     */
    private const CACHE_DIR = BP . '/var/falcosense';

    public function __construct(
        private readonly Data            $helper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns a valid short-lived token for the given Magento store.
     * Uses file cache — only calls the platform when token is missing or about to expire,
     * unless $forceRefresh is set (used when a caller already knows the cached token was rejected).
     */
    public function getToken(int $magentoStoreId = 0, bool $forceRefresh = false): string
    {
        return $this->getTokenData($magentoStoreId, $forceRefresh)['token'];
    }

    /**
     * Same as getToken(), but also returns the expiry so callers can compute a TTL.
     */
    public function getTokenData(int $magentoStoreId = 0, bool $forceRefresh = false): array
    {
        $cacheFile = $this->getCacheFilePath($magentoStoreId);

        // Check file cache
        if (!$forceRefresh && file_exists($cacheFile)) {
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
            if ($this->ensureCacheDirReady()) {
                $written = @file_put_contents($cacheFile, json_encode($token), LOCK_EX);
                if ($written === false) {
                    $this->logger->warning('[SmartSearch] SearchTokenService: failed to write token cache file ' . $cacheFile);
                }
            }
            return $token;
        }

        // Fallback: caller should handle gracefully
        return ['token' => '', 'expires_at' => null];
    }

    /**
     * Creates CACHE_DIR on first use if it doesn't exist yet. Unlike a bare
     * file_put_contents() to a missing directory (which just fails), this lets
     * the cache start working from a clean deploy without anyone having to
     * manually create/seed the directory first.
     */
    private function ensureCacheDirReady(): bool
    {
        if (is_dir(self::CACHE_DIR)) {
            return is_writable(self::CACHE_DIR);
        }
        if (!@mkdir(self::CACHE_DIR, 0770, true) && !is_dir(self::CACHE_DIR)) {
            $this->logger->warning('[SmartSearch] SearchTokenService: could not create cache dir ' . self::CACHE_DIR);
            return false;
        }
        return is_writable(self::CACHE_DIR);
    }

    /**
     * Cache file is namespaced by a hash of this install's own API key (not just the
     * Magento store id), because this box hosts multiple separate Magento installs
     * (dev1, dev2, staging, staging2, everest) that each get their own var/falcosense/
     * directory (this install's own var/), but could still collide on store id alone
     * if two stores under the same install ever shared id 1 — keying by API key hash
     * keeps every cache file unique regardless.
     */
    private function getCacheFilePath(int $magentoStoreId): string
    {
        $apiKey  = (string) $this->helper->getApiKey($magentoStoreId);
        $keyHash = substr(sha1($apiKey), 0, 16);

        return self::CACHE_DIR . '/token_' . $keyHash . '_' . $magentoStoreId . '.json';
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
