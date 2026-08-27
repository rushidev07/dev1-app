<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\SellerPricing;

use Ahy\CaliberNation\Model\SellerParticipationFactory;
use Ahy\CaliberNation\Model\ResourceModel\SellerParticipation as SellerResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::seller_pricing';

    public function __construct(
        Context $context,
        private readonly SellerParticipationFactory $factory,
        private readonly SellerResource $resource
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $redirect->setPath('*/*/');
        }

        try {
            $model = $this->factory->create();
            $id = (int) ($data['entity_id'] ?? 0);
            if ($id) {
                $this->resource->load($model, $id);
                if (!$model->getId()) {
                    $this->messageManager->addErrorMessage(__('This record no longer exists.'));
                    return $redirect->setPath('*/*/');
                }
            } else {
                unset($data['entity_id']);
            }

            // ui-select may submit seller_id as an array — normalise to a single int.
            if (isset($data['seller_id']) && \is_array($data['seller_id'])) {
                $data['seller_id'] = (int) reset($data['seller_id']);
            }

            // Discount value is now optional (participate without a blanket discount →
            // use per-product Member Discount instead). Blank normalises to 0 so the
            // NOT NULL column is satisfied and no seller-wide discount is applied.
            if (!isset($data['discount_value']) || $data['discount_value'] === '') {
                $data['discount_value'] = 0;
            }

            $model->addData($data);
            $this->resource->save($model);
            $this->messageManager->addSuccessMessage(__('Seller participation saved.'));

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['entity_id' => $model->getId()]);
            }
            return $redirect->setPath('*/*/');
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not save: %1', $e->getMessage()));
            return $redirect->setPath('*/*/edit', ['entity_id' => (int) ($data['entity_id'] ?? 0)]);
        }
    }
}
