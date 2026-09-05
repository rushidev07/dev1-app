<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\SellerPricing;

use Magento\Backend\App\Action;

class NewAction extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::seller_pricing';

    public function execute()
    {
        $resultForward = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_FORWARD);
        return $resultForward->forward('edit');
    }
}
