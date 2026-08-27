<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\Product;
use Ahy\FlxPointApproval\Model\ResourceModel\Product as ProductResource;
use Ahy\FlxPointApproval\Model\ResourceModel\Product\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class MassReject extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval_reject';

    private CollectionFactory $collectionFactory;
    private Product $productModel;
    private ProductResource $productResource;

    public function __construct(
        Context $context,
        CollectionFactory $collectionFactory,
        Product $productModel,
        ProductResource $productResource
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->productModel      = $productModel;
        $this->productResource   = $productResource;
        parent::__construct($context);
    }

    public function execute()
    {
        $reason    = trim((string) $this->getRequest()->getParam('rejection_reason', ''));
        $ids       = $this->_session->getFlxpointMassRejectIds() ?? [];
        $redirect  = $this->resultRedirectFactory->create();

        if (empty($ids)) {
            $this->messageManager->addErrorMessage(__('No products selected for rejection.'));
            return $redirect->setPath('*/*/index');
        }

        $this->_session->unsFlxpointMassRejectIds();

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);

        $adminUser = $this->_auth->getUser();
        $count     = 0;

        foreach ($collection as $item) {
            try {
                $product = clone $this->productModel;
                $this->productResource->load($product, $item->getId());
                $product->setStatus(Product::STATUS_REJECTED);
                $product->setRejectionReason($reason ?: null);
                $product->setReviewedBy((int) $adminUser->getId());
                $product->setReviewedAt(date('Y-m-d H:i:s'));
                $this->productResource->save($product);
                $count++;
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage(
                    __('Error rejecting "%1": %2', $item->getName(), $e->getMessage())
                );
            }
        }

        if ($count) {
            $this->messageManager->addSuccessMessage(
                __('%1 product(s) rejected successfully.', $count)
            );
        }

        return $redirect->setPath('*/*/index');
    }
}
