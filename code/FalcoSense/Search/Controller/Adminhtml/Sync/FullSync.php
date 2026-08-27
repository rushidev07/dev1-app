<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Adminhtml\Sync;

use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use FalcoSense\Search\Service\SyncLockManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class FullSync extends Action
{
    const ADMIN_RESOURCE = 'FalcoSense_Search::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory       $jsonFactory,
        private readonly SyncLockManager   $lockManager,
        private readonly SmartSearchHelper $helper,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->getRequest()->isPost()) {
            return $result->setData(['success' => false, 'message' => 'Invalid request.']);
        }

        if (!$this->helper->isEnabled()) {
            return $result->setData(['success' => false, 'message' => 'Smart Search sync is disabled.']);
        }

        if (!$this->helper->getEndpointUrl()) {
            return $result->setData(['success' => false, 'message' => 'Platform Endpoint URL is not configured.']);
        }

        if (!$this->helper->getApiKey()) {
            return $result->setData(['success' => false, 'message' => 'API Key is not configured.']);
        }

        if ($this->lockManager->isLocked()) {
            $info = $this->lockManager->getLockInfo();
            return $result->setData(['success' => false,
                'message' => "Sync already running (via {$info['source']}, started {$info['started']}). Reload to refresh status."]);
        }

        if (!$this->lockManager->acquire('admin')) {
            return $result->setData(['success' => false, 'message' => 'Could not acquire lock.']);
        }

        // ?store=X is set by Magento when admin is scoped to a store view; 0 = default (all stores)
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        $php = $this->findPhpCli();
        $bin = BP . '/bin/magento';
        $log = BP . '/var/log/smartsearch-full-sync.log';

        $cmd = sprintf(
            'env -i HOME=%s PATH=%s %s %s smartsearch:sync:full --force --store=%d < /dev/null >> %s 2>&1 &',
            escapeshellarg((string)(getenv('HOME') ?: '/tmp')),
            escapeshellarg((string)(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')),
            escapeshellarg($php),
            escapeshellarg($bin),
            $storeId,
            escapeshellarg($log)
        );

        @shell_exec($cmd);

        return $result->setData(['success' => true, 'message' => 'Full sync started in background.']);
    }

    private function findPhpCli(): string
    {
        $binary = PHP_BINARY;
        if (!str_contains(basename($binary), 'cgi') && !str_contains(basename($binary), 'fpm')) {
            return $binary;
        }
        $sibling = dirname($binary) . '/php';
        if (file_exists($sibling) && is_executable($sibling)) {
            return $sibling;
        }
        $which = trim((string) @shell_exec('which php 2>/dev/null'));
        return ($which && file_exists($which)) ? $which : $binary;
    }
}
