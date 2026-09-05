<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class RejectForm extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval_reject';

    private PageFactory $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Invalid product ID.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Reject Product'));
        return $resultPage;
    }
}
