<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires on: controller_action_postdispatch_search_index_index
 * Reads ?q= from the request — works with Klevu which never saves to catalogsearch_query.
 */
class SearchQueryObserver implements ObserverInterface
{
    private const LOG_FILE = BP . '/var/log/smartsearch-analytics.log';

    public function __construct(
        private readonly Data                  $helper,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface      $request,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            $queryText = trim((string) $this->request->getParam('q', ''));

            if (strlen($queryText) < 1) {
                return;
            }

            $storeId = (int) $this->storeManager->getStore()->getId();
            $this->log("query=\"{$queryText}\" store={$storeId}");
            $this->send($queryText, $storeId);

        } catch (\Throwable $e) {
            $this->log('ERROR: ' . $e->getMessage());
            $this->logger->error('[SmartSearch][Search] SearchQueryObserver error: ' . $e->getMessage());
        }
    }

    private function send(string $query, int $storeId): void
    {
        $ingestUrl = $this->helper->getEndpointUrl($storeId);
        if (!$ingestUrl) {
            $this->log('SKIP: no endpoint URL');
            return;
        }

        $parts  = parse_url($ingestUrl);
        $base   = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) $base .= ':' . $parts['port'];
        $url    = $base . '/api/v1/analytics/search';
        $apiKey = $this->helper->getApiKey($storeId);

        if (!$apiKey) {
            $this->log('SKIP: no API key');
            return;
        }

        $payload = json_encode(['query' => $query, 'result_count' => 0, 'response_time_ms' => 0, 'page' => 1]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $apiKey],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log("cURL ERROR: {$err}");
        } else {
            $this->log("HTTP={$httpCode} " . substr((string) $raw, 0, 100));
        }
    }

    private function log(string $msg): void
    {
        file_put_contents(self::LOG_FILE, '[' . date('Y-m-d H:i:s') . '] [SearchQueryObserver] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
