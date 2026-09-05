<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\Membership;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Service\ActivateMembership;
use Ahy\CaliberNation\Model\Service\ActivityLogger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;

/**
 * Admin manual status override for a membership, with group sync + audited to the
 * activity log (attributed to the admin user).
 */
class SetStatus extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::membership';

    public function __construct(
        Context $context,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly ActivateMembership $activateMembership,
        private readonly ActivityLogger $activityLogger,
        private readonly AuthSession $authSession
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $id     = (int) $this->getRequest()->getParam('id');
        $status = (string) $this->getRequest()->getParam('status');

        if (!\in_array($status, [
            MembershipInterface::STATUS_ACTIVE,
            MembershipInterface::STATUS_RENEWAL_PENDING,
            MembershipInterface::STATUS_EXPIRED,
            MembershipInterface::STATUS_CANCELLED,
        ], true)) {
            $this->messageManager->addErrorMessage(__('Invalid status.'));
            return $redirect;
        }

        try {
            $membership = $this->membershipRepository->getById($id);
            $customerId = (int) $membership->getCustomerId();

            $membership->setStatus($status);
            $this->membershipRepository->save($membership);

            // Keep the member customer group in sync with the new status.
            if ($status === MembershipInterface::STATUS_ACTIVE) {
                $this->activateMembership->assignMemberGroup($customerId);
            } else {
                $this->activateMembership->revertMemberGroup($customerId);
            }

            $adminId = (int) ($this->authSession->getUser() ? $this->authSession->getUser()->getId() : 0);
            $this->activityLogger->log(
                $customerId,
                ActivityLogger::ACTION_STATUS_CHANGE,
                'admin set status=' . $status,
                null,
                $adminId ?: null
            );

            $this->messageManager->addSuccessMessage(__('Membership status set to "%1".', $status));
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not update status: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
