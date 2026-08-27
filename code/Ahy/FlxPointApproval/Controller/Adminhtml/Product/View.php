<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\Product;
use Ahy\FlxPointApproval\Model\ResourceModel\Product as ProductResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class View extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval';

    private Product $productModel;
    private ProductResource $productResource;
    private PageFactory $pageFactory;

    public function __construct(
        Context $context,
        Product $productModel,
        ProductResource $productResource,
        PageFactory $pageFactory
    ) {
        $this->productModel    = $productModel;
        $this->productResource = $productResource;
        $this->pageFactory     = $pageFactory;
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $this->productResource->load($this->productModel, $entityId);

        if (!$this->productModel->getId()) {
            $this->messageManager->addErrorMessage(__('Product not found.'));
            return $this->resultRedirectFactory->create()
                ->setPath('ahy_flxpoint_approval/product/index');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(
            __('Product Details: %1', $this->productModel->getData('name'))
        );

        $page->getLayout()
            ->getBlock('flxpoint_approval_product_view')
            ?->setProduct($this->productModel);

        return $page;
    }
}
