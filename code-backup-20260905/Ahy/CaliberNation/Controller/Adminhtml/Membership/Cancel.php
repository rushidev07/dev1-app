<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\Membership;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Service\MembershipManagementService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;

class Cancel extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::membership';

    public function __construct(
        Context $context,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly MembershipManagementService $managementService
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $id = (int) $this->getRequest()->getParam('id');

        try {
            $customerId = (int) $this->membershipRepository->getById($id)->getCustomerId();
            $result = $this->managementService->cancelMembership($customerId);
            if ($result['success']) {
                $this->messageManager->addSuccessMessage(__('Membership cancelled.'));
            } else {
                $this->messageManager->addErrorMessage($result['message']);
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not cancel this membership: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
