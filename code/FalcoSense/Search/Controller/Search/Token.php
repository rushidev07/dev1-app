<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Search;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\SearchTokenService;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Returns a fresh short-lived search token for the current store.
 * Called client-side when the embedded token has expired (401 from platform).
 * Not FPC-cached because it is an AJAX/JSON endpoint.
 */
class Token extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context                              $context,
        private readonly JsonFactory         $jsonFactory,
        private readonly SearchTokenService  $tokenService,
        private readonly Data                $helper,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
            // "force" is set by the client only when it is retrying after a 401 from
            // the platform — i.e. it already knows the cached token was rejected, so
            // the local file cache must be bypassed rather than trusted.
            $forceRefresh = (bool) $this->getRequest()->getParam('force');
            $tokenData = $this->tokenService->getTokenData($storeId, $forceRefresh);

            if ($tokenData['token'] === '') {
                return $result->setHttpResponseCode(503)
                    ->setData(['success' => false, 'error' => 'Token service unavailable.']);
            }

            $ttlSeconds = $tokenData['expires_at']
                ? max(0, strtotime($tokenData['expires_at']) - time())
                : null;

            return $result->setData([
                'success'     => true,
                'token'       => $tokenData['token'],
                'ttl_seconds' => $ttlSeconds,
            ]);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['success' => false, 'error' => 'Internal error.']);
        }
    }
}
