<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Lists every recorded alert run.
 *
 * Reached from Catalog > Discount Alerts, so past runs stay available even when the emailed
 * link has been lost, forwarded away or expired.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Ahy_DiscountAlert::manage';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ahy_DiscountAlert::runs');
        $resultPage->getConfig()->getTitle()->prepend(__('Discount Alerts'));

        return $resultPage;
    }
}
