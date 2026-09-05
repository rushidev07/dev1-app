<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Search;

use FalcoSense\Search\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces Klevu\Search\Controller\Index\Index.
 * Renders our native search results page and logs the query to the platform.
 */
class Index extends Action
{
    private const LOG_FILE = BP . '/var/log/smartsearch-analytics.log';

    public function __construct(
        Context                              $context,
        private readonly PageFactory         $pageFactory,
        private readonly Data                $helper,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface     $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $queryText = trim((string) $this->getRequest()->getParam('q', ''));

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__("Search results for: '%1'", $queryText));
        return $page;
    }

    private function trackSearch(string $query): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        try {
            $storeId   = (int) $this->storeManager->getStore()->getId();
            $ingestUrl = $this->helper->getEndpointUrl($storeId);
            $apiKey    = $this->helper->getApiKey($storeId);

            if (!$ingestUrl || !$apiKey) {
                return;
            }

            $parts = parse_url($ingestUrl);
            $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
            if (!empty($parts['port'])) $base .= ':' . $parts['port'];
            $url = $base . '/api/v1/analytics/search';

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

            $logMsg = $raw === false
                ? "cURL ERROR: {$err}"
                : "query=\"{$query}\" store={$storeId} HTTP={$httpCode}";

            $this->log($logMsg);

        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearch][Search] trackSearch error: ' . $e->getMessage());
        }
    }

    private function log(string $msg): void
    {
        file_put_contents(self::LOG_FILE, '[' . date('Y-m-d H:i:s') . '] [SearchController] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
