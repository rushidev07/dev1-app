<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\Product;
use Ahy\FlxPointApproval\Model\ResourceModel\Product as ProductResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Reject extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval_reject';

    private Product $productModel;
    private ProductResource $productResource;

    public function __construct(
        Context $context,
        Product $productModel,
        ProductResource $productResource
    ) {
        $this->productModel    = $productModel;
        $this->productResource = $productResource;
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
        $reason   = trim((string) $this->getRequest()->getParam('rejection_reason', ''));
        $redirect = $this->resultRedirectFactory->create();

        if (!$entityId) {
            $this->messageManager->addErrorMessage(__('Invalid product ID.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $product = clone $this->productModel;
            $this->productResource->load($product, $entityId);

            if (!$product->getId()) {
                $this->messageManager->addErrorMessage(__('Product not found.'));
                return $redirect->setPath('*/*/index');
            }

            $adminUser = $this->_auth->getUser();
            $product->setStatus(Product::STATUS_REJECTED);
            $product->setRejectionReason($reason ?: null);
            $product->setReviewedBy((int) $adminUser->getId());
            $product->setReviewedAt(date('Y-m-d H:i:s'));
            $this->productResource->save($product);

            $this->messageManager->addSuccessMessage(
                __('Product "%1" has been rejected.', $product->getName())
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error: %1', $e->getMessage()));
        }

        return $redirect->setPath('*/*/index');
    }
}
