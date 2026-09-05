<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\Product;
use Ahy\FlxPointApproval\Model\ResourceModel\Product as ProductResource;
use Ahy\FlxPointApproval\Service\ImportProductService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Model\Context as ModelContext;

class Approve extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval_approve';

    private Product $productModel;
    private ProductResource $productResource;
    private ImportProductService $importService;

    public function __construct(
        Context $context,
        Product $productModel,
        ProductResource $productResource,
        ImportProductService $importService
    ) {
        $this->productModel    = $productModel;
        $this->productResource = $productResource;
        $this->importService   = $importService;
        parent::__construct($context);
    }

    public function execute()
    {
        $entityId = (int) $this->getRequest()->getParam('entity_id');
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

            $result = $this->importService->execute($entityId);

            if ($result['success']) {
                $adminUser = $this->_auth->getUser();
                $product->setStatus(Product::STATUS_APPROVED);
                $product->setReviewedBy((int) $adminUser->getId());
                $product->setReviewedAt(date('Y-m-d H:i:s'));
                $product->setImportError(null);
                $this->productResource->save($product);

                $reindexResult = $this->importService->reindexAndFlushCache();
                if ($reindexResult['success']) {
                    $this->messageManager->addSuccessMessage(
                        __('Product "%1" has been approved and imported successfully.', $product->getName())
                    );
                } else {
                    $this->messageManager->addWarningMessage(
                        __(
                            'Product "%1" was imported, but reindex/cache-flush failed — it may not appear on'
                            . ' the storefront yet. Run `bin/magento indexer:reindex && bin/magento cache:flush`'
                            . ' manually. Details: %2',
                            $product->getName(),
                            $reindexResult['message']
                        )
                    );
                }
            } else {
                $product->setImportError($result['message']);
                $this->productResource->save($product);
                $this->messageManager->addErrorMessage(
                    __('Approval recorded but import failed: %1', $result['message'])
                );
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error: %1', $e->getMessage()));
        }

        return $redirect->setPath('*/*/index');
    }
}
