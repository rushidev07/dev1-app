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

class Delete extends Action
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
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        $id = (int) $this->getRequest()->getParam('entity_id');
        if (!$id) {
            return $redirect;
        }
        try {
            $model = $this->factory->create();
            $this->resource->load($model, $id);
            if ($model->getId()) {
                $this->resource->delete($model);
                $this->messageManager->addSuccessMessage(__('Member price rule deleted.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not delete: %1', $e->getMessage()));
        }
        return $redirect;
    }
}
