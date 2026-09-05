<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\PriceRule;

use Ahy\CaliberNation\Model\MemberPriceRuleFactory;
use Ahy\CaliberNation\Model\ResourceModel\MemberPriceRule as RuleResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Save extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::price_rules';

    public function __construct(
        Context $context,
        private readonly MemberPriceRuleFactory $factory,
        private readonly RuleResource $resource
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

            // ui-select may submit target_id as an array — normalise to a single int.
            if (isset($data['target_id']) && \is_array($data['target_id'])) {
                $data['target_id'] = (int) reset($data['target_id']);
            }

            // Prevent duplicate: check if this category already has a rule.
            if (!$id && !empty($data['target_id'])) {
                $conn   = $this->resource->getConnection();
                $table  = $this->resource->getMainTable();
                $exists = $conn->fetchOne(
                    $conn->select()->from($table, ['entity_id'])
                        ->where('target_id = ?', (int) $data['target_id'])
                        ->limit(1)
                );
                if ($exists) {
                    $this->messageManager->addErrorMessage(
                        __('This category already has a discount rule. Please edit the existing record instead.')
                    );
                    return $redirect->setPath('*/*/new');
                }
            }

            $model->addData($data);
            $this->resource->save($model);
            $this->messageManager->addSuccessMessage(__('Member price rule saved.'));

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
