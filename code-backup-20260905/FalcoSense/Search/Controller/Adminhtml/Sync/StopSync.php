<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Adminhtml\Sync;

use FalcoSense\Search\Service\SyncLockManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class StopSync extends Action
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
        $result = $this->jsonFactory->create();

        if (!$this->getRequest()->isPost()) {
            return $result->setData(['success' => false, 'message' => 'Invalid request.']);
        }

        if (!$this->lockManager->isLocked()) {
            return $result->setData(['success' => false, 'message' => 'No sync is currently running.']);
        }

        $this->lockManager->release();

        return $result->setData([
            'success' => true,
            'message' => 'Stop signal sent. The running sync will abort at the end of its current batch.',
        ]);
    }
}
