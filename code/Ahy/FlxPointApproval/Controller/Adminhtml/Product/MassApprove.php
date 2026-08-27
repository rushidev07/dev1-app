<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Controller\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\Product;
use Ahy\FlxPointApproval\Model\ResourceModel\Product as ProductResource;
use Ahy\FlxPointApproval\Model\ResourceModel\Product\CollectionFactory;
use Ahy\FlxPointApproval\Service\ImportProductService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Ui\Component\MassAction\Filter;

class MassApprove extends Action
{
    const ADMIN_RESOURCE = 'Ahy_FlxPointApproval::product_approval_approve';

    private Filter $filter;
    private CollectionFactory $collectionFactory;
    private Product $productModel;
    private ProductResource $productResource;
    private ImportProductService $importService;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        Product $productModel,
        ProductResource $productResource,
        ImportProductService $importService
    ) {
        $this->filter            = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->productModel      = $productModel;
        $this->productResource   = $productResource;
        $this->importService     = $importService;
        parent::__construct($context);
    }

    public function execute()
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());
        $adminUser  = $this->_auth->getUser();
        $approved   = 0;
        $failed     = 0;

        foreach ($collection as $item) {
            try {
                $result = $this->importService->execute((int) $item->getId());

                $product = clone $this->productModel;
                $this->productResource->load($product, $item->getId());

                if ($result['success']) {
                    $product->setStatus(Product::STATUS_APPROVED);
                    $product->setImportError(null);
                    $approved++;
                } else {
                    $product->setImportError($result['message']);
                    $failed++;
                }

                $product->setReviewedBy((int) $adminUser->getId());
                $product->setReviewedAt(date('Y-m-d H:i:s'));
                $this->productResource->save($product);
            } catch (\Exception $e) {
                $failed++;
                $this->messageManager->addErrorMessage(
                    __('Error approving "%1": %2', $item->getName(), $e->getMessage())
                );
            }
        }

        if ($approved) {
            // One reindex/cache-flush for the whole batch, not per product — otherwise mass-approving
            // many products would trigger a full reindex once per item.
            $reindexResult = $this->importService->reindexAndFlushCache();

            if ($reindexResult['success']) {
                $this->messageManager->addSuccessMessage(
                    __('%1 product(s) approved and imported successfully.', $approved)
                );
            } else {
                $this->messageManager->addWarningMessage(
                    __(
                        '%1 product(s) were imported, but reindex/cache-flush failed — they may not appear on'
                        . ' the storefront yet. Run `bin/magento indexer:reindex && bin/magento cache:flush`'
                        . ' manually. Details: %2',
                        $approved,
                        $reindexResult['message']
                    )
                );
            }
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(
                __('%1 product(s) failed to import. Check the Import Error column for details.', $failed)
            );
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
