<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\ResourceModel\Product\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Ui\Component\MassAction\Filter;

class MassRejectForm extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval_reject';

    private Filter $filter;
    private CollectionFactory $collectionFactory;
    private PageFactory $resultPageFactory;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        PageFactory $resultPageFactory
    ) {
        $this->filter            = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $ids        = $collection->getAllIds();

        // Store selected IDs in session so the mass reject POST can retrieve them
        $this->_session->setFlxpointMassRejectIds($ids);

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(
            __('Reject %1 Product(s)', count($ids))
        );
        return $resultPage;
    }
}
