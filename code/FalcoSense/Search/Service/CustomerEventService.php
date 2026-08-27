<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use FalcoSense\Search\Helper\Data;
use Psr\Log\LoggerInterface;

/**
 * Sends visitor/customer behavioural events to the platform's /api/v1/events endpoint.
 *
 * GDPR design principles applied here:
 *  - Data minimisation: only fields needed for product analytics are sent.
 *  - Pseudonymisation: customer_id is Magento's internal integer — not directly
 *    identifying without access to the Magento DB. Never send name or email.
 *  - IP anonymisation: last octet (IPv4) or last 80 bits (IPv6) are zeroed before
 *    leaving this server. The raw IP is never forwarded.
 *  - visitor_id (first-party cookie _ahy_vid): behavioural tracking — requires a
 *    cookie consent banner on the storefront before this cookie is set.
 *  - Timeout 2s: observer events must never block the page render.
 *
 * What is intentionally NOT sent:
 *  - Customer name, email, email hash, phone, address — not needed for product ranking.
 *  - Raw IP address — anonymised to subnet level before sending.
 */
class CustomerEventService
{
    private const COOKIE_NAME = '_ahy_vid';
    private const TIMEOUT_SEC = 2;

    public function __construct(
        private readonly Data            $helper,
        private readonly LoggerInterface $logger,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Public event methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Logged-in customer login. Only the internal customer_id (pseudonymous) is sent.
     * Name and email are intentionally excluded — GDPR data minimisation.
     */
    public function trackLogin(int $customerId, int $storeId = 0): void
    {
        $this->send([
            'event_type'  => 'customer_login',
            'customer_id' => (string) $customerId,
        ], $storeId);
    }

    /**
     * New customer registration. Only the internal customer_id is sent.
     */
    public function trackRegister(int $customerId, int $storeId = 0): void
    {
        $this->send([
            'event_type'  => 'customer_register',
            'customer_id' => (string) $customerId,
        ], $storeId);
    }

    public function trackLogout(int $customerId, int $storeId = 0): void
    {
        $this->send([
            'event_type'  => 'customer_logout',
            'customer_id' => (string) $customerId,
        ], $storeId);
    }

    public function trackProductView(
        int    $productId,
        string $sku,
        string $name,
        float  $price,
        ?int   $customerId,
        int    $storeId = 0
    ): void {
        $payload = [
            'event_type'    => 'product_view',
            'product_id'    => (string) $productId,
            'product_sku'   => $sku,
            'product_name'  => $name,
            'product_price' => $price,
        ];
        if ($customerId !== null) {
            $payload['customer_id'] = (string) $customerId;
        }
        $this->send($payload, $storeId);
    }

    public function trackAddToCart(
        int    $productId,
        string $sku,
        string $name,
        float  $price,
        float  $qty,
        ?int   $customerId,
        int    $storeId = 0
    ): void {
        $payload = [
            'event_type'    => 'add_to_cart',
            'product_id'    => (string) $productId,
            'product_sku'   => $sku,
            'product_name'  => $name,
            'product_price' => $price,
            'quantity'      => $qty,
        ];
        if ($customerId !== null) {
            $payload['customer_id'] = (string) $customerId;
        }
        $this->send($payload, $storeId);
    }

    public function trackRemoveFromCart(
        int    $productId,
        string $sku,
        string $name,
        float  $price,
        float  $qty,
        ?int   $customerId,
        int    $storeId = 0
    ): void {
        $payload = [
            'event_type'    => 'remove_from_cart',
            'product_id'    => (string) $productId,
            'product_sku'   => $sku,
            'product_name'  => $name,
            'product_price' => $price,
            'quantity'      => $qty,
        ];
        if ($customerId !== null) {
            $payload['customer_id'] = (string) $customerId;
        }
        $this->send($payload, $storeId);
    }

    public function trackWishlistAdd(
        int    $productId,
        string $sku,
        string $name,
        float  $price,
        ?int   $customerId,
        int    $storeId = 0
    ): void {
        $payload = [
            'event_type'    => 'wishlist_add',
            'product_id'    => (string) $productId,
            'product_sku'   => $sku,
            'product_name'  => $name,
            'product_price' => $price,
        ];
        if ($customerId !== null) {
            $payload['customer_id'] = (string) $customerId;
        }
        $this->send($payload, $storeId);
    }

    /**
     * Purchase event — handles both guest and logged-in customers.
     *
     * Guest checkout ($customerId = null):
     *   Only order_id (pseudonymous) + product line items are sent.
     *   No identifying information whatsoever.
     *
     * Logged-in checkout ($customerId set):
     *   customer_id (pseudonymous internal ID) is added.
     *   Still no name, email, or address — GDPR data minimisation.
     *
     * Each item becomes one event row on the platform (expanded by EventService).
     * grand_total is sent for revenue analytics; individual item row_total for product revenue.
     */
    public function trackPurchase(
        string $orderId,
        string $orderNumber,
        float  $grandTotal,
        string $currency,
        array  $items,
        ?int   $customerId,
        int    $storeId = 0
    ): void {
        $payload = [
            'event_type'   => 'purchase',
            'order_id'     => $orderId,
            'order_number' => $orderNumber,
            'grand_total'  => $grandTotal,
            'currency'     => $currency,
            'items'        => $items,
        ];
        if ($customerId !== null) {
            $payload['customer_id'] = (string) $customerId;
        }
        $this->send($payload, $storeId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private const LOG_FILE = BP . '/var/log/smartsearch-analytics.log';

    private function log(string $msg): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [CustomerEventService] ' . $msg . PHP_EOL;
        file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }

    private function send(array $eventData, int $storeId): void
    {
        $eventType = $eventData['event_type'] ?? '?';
        $this->log("send() called for event_type={$eventType} storeId={$storeId}");

        if (!$this->helper->isEnabled()) {
            $this->log('SKIP: SmartSearch disabled');
            return;
        }

        $url    = $this->helper->getEventsEndpointUrl($storeId);
        $apiKey = $this->helper->getApiKey($storeId);

        $this->log("url={$url} apiKey=" . substr($apiKey, 0, 8) . '...');

        if (!$url || !$apiKey) {
            $this->log('SKIP: url or apiKey is empty');
            return;
        }

        $platformStoreId = $this->helper->getPlatformStoreId($storeId);

        $payload = array_merge($eventData, [
            'store_id'   => $platformStoreId,
            'visitor_id' => $_COOKIE[self::COOKIE_NAME] ?? null,
            'session_id' => session_id() ?: null,
            // Anonymised to subnet: 1.2.3.4 → 1.2.3.0 — raw IP never leaves Magento.
            'ip_address' => $this->anonymizedIp(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512)
                : null,
        ]);

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
                'X-Signature: sha256=' . hash_hmac('sha256', $json, $apiKey),
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        $eventType = $eventData['event_type'] ?? '?';

        if ($raw === false) {
            $this->log("cURL ERROR for {$eventType}: {$err}");
            $this->logger->warning('[SmartSearch][Events] cURL error for ' . $eventType . ': ' . $err);
            return;
        }

        $this->log("HTTP={$httpCode} response=" . substr((string) $raw, 0, 300));

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->warning(sprintf(
                '[SmartSearch][Events] HTTP %d for %s: %s',
                $httpCode,
                $eventType,
                substr((string) $raw, 0, 500)
            ));
            return;
        }

        $this->logger->info(sprintf(
            '[SmartSearch][Events] Tracked %s HTTP=%d visitor=%s customer=%s response=%s',
            $eventType,
            $httpCode,
            $payload['visitor_id'] ?? 'none',
            $eventData['customer_id'] ?? 'guest',
            substr((string) $raw, 0, 200)
        ));
    }

    /**
     * Returns the client IP anonymised to subnet level.
     *
     * IPv4: zeroes the last octet    — 203.0.113.42  → 203.0.113.0
     * IPv6: zeroes the last 80 bits  — 2001:db8::1   → 2001:db8::
     *
     * This satisfies the German DPA (DSK) guidance that IP addresses
     * anonymised to /24 (IPv4) or /48 (IPv6) are no longer personal data
     * when the full address is not stored elsewhere in the same system.
     */
    private function anonymizedIp(): ?string
    {
        $raw = null;
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $raw = trim(explode(',', $_SERVER[$key])[0]);
                break;
            }
        }

        if ($raw === null) {
            return null;
        }

        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Zero last octet: 1.2.3.4 → 1.2.3.0
            return substr($raw, 0, strrpos($raw, '.') + 1) . '0';
        }

        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Expand, zero last 80 bits (5 groups), re-compress
            $bin    = inet_pton($raw);
            $zeroed = substr($bin, 0, 6) . str_repeat("\x00", 10);
            return inet_ntop($zeroed);
        }

        return null;
    }
}
