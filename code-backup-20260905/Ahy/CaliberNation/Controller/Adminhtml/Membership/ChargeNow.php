<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\Membership;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Service\RenewMembership;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class ChargeNow extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::membership';

    public function __construct(
        Context $context,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly RenewMembership $renewMembership
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $id = (int) $this->getRequest()->getParam('id');

        try {
            $membership = $this->membershipRepository->getById($id);
            $result = $this->renewMembership->renew($membership);
            if ($result['success']) {
                $this->messageManager->addSuccessMessage(__('Membership renewed: %1', $result['message']));
            } else {
                $this->messageManager->addErrorMessage(__('Renewal failed: %1', $result['message']));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not charge this membership: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
