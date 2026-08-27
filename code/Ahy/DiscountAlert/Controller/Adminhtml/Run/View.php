<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Controller\Adminhtml\Run;

use Ahy\DiscountAlert\Api\RunRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;

/**
 * Landing page for the "Review & Disable" button in the alert email.
 *
 * Read-only. Reached by a deep link from email, so admin authentication still applies:
 * the user is bounced to the login screen and returned here afterwards.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ahy_DiscountAlert::manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly RunRepositoryInterface $runRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $runId = (int) $this->getRequest()->getParam('run_id');

        try {
            $run = $this->runRepository->getByToken($runId, (string) $this->getRequest()->getParam('token'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $this->resultRedirectFactory->create()->setPath('adminhtml/dashboard');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Discount Alert: Run #%1', $run->getRunId()));

        return $resultPage;
    }

    /**
     * Skip admin secret-key validation for this action only.
     *
     * Cron and CLI build the emailed link without an admin session and therefore cannot
     * mint a secret key. Everything else still applies: the user must authenticate, the
     * ADMIN_RESOURCE ACL check runs, and the run token decides which run is readable.
     * This action performs no writes; the Disable action keeps full form-key and
     * secret-key validation.
     *
     * Declared public to match Magento\Backend\App\AbstractAction.
     */
    public function _processUrlKeys(): bool
    {
        return true;
    }
}
