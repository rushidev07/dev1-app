<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\SellerPricing;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::seller_pricing';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int) $this->getRequest()->getParam('entity_id');
        $result = $this->resultPageFactory->create();
        $result->setActiveMenu('Ahy_CaliberNation::seller_pricing');
        $result->getConfig()->getTitle()->prepend($id ? __('Edit Seller Participation') : __('New Seller Participation'));
        return $result;
    }
}
