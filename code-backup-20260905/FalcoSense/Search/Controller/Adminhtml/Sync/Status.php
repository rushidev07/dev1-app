<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Adminhtml\Sync;

use FalcoSense\Search\Service\SyncLockManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class Status extends Action
{
    const ADMIN_RESOURCE = 'FalcoSense_Search::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory     $jsonFactory,
        private readonly SyncLockManager $lockManager,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $locked = $this->lockManager->isLocked();
        $info   = $locked ? $this->lockManager->getLockInfo() : null;

        return $this->jsonFactory->create()->setData([
            'locked'      => $locked,
            'source'      => $info['source']  ?? null,
            'started'     => $info['started'] ?? null,
            'last_result' => $this->lockManager->getLastResult(),
        ]);
    }
}
