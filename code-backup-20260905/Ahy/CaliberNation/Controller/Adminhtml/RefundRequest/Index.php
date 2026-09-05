<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\RefundRequest;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::refund_requests';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Ahy_CaliberNation::refund_requests');
        $page->getConfig()->getTitle()->prepend(__('Early Cancellations'));
        return $page;
    }
}
