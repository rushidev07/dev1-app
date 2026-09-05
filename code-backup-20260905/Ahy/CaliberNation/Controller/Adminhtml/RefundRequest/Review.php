<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Adminhtml\RefundRequest;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\RefundRequest;
use Ahy\CaliberNation\Model\ResourceModel\RefundRequest as RefundRequestResource;
use Ahy\CaliberNation\Model\RefundRequestFactory;
use Ahy\CaliberNation\Model\Service\ActivateMembership;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\NoSuchEntityException;

class Review extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_CaliberNation::refund_requests';

    public function __construct(
        Context $context,
        private readonly RefundRequestResource $refundRequestResource,
        private readonly RefundRequestFactory $refundRequestFactory,
        private readonly AuthSession $authSession,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly ActivateMembership $activateMembership
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $id       = (int) $this->getRequest()->getParam('id');

        try {
            /** @var RefundRequest $refundRequest */
            $refundRequest = $this->refundRequestFactory->create();
            $this->refundRequestResource->load($refundRequest, $id);

            if (!$refundRequest->getId()) {
                $this->messageManager->addErrorMessage(__('Refund request not found.'));
                return $redirect;
            }

            $adminId      = (int) ($this->authSession->getUser() ? $this->authSession->getUser()->getId() : 0);
            $isReviewed   = $refundRequest->getData('status') === RefundRequest::STATUS_REVIEWED;
            $membershipId = (int) $refundRequest->getData('membership_id');
            $customerId   = (int) $refundRequest->getData('customer_id');

            if ($isReviewed) {
                // Revert to pending — restore membership to cancelled so paid-through
                // window is active again.
                $refundRequest->setData('status', RefundRequest::STATUS_PENDING);
                $refundRequest->setData('reviewed_by', null);
                $refundRequest->setData('reviewed_at', null);
                $this->refundRequestResource->save($refundRequest);

                $this->restoreMembershipToCancelled($membershipId, $customerId);

                $this->messageManager->addSuccessMessage(__('Refund request reverted to pending.'));
            } else {
                // Mark reviewed — expire the membership immediately so member loses
                // access and can re-purchase a fresh membership.
                $refundRequest->setData('status', RefundRequest::STATUS_REVIEWED);
                $refundRequest->setData('reviewed_by', $adminId ?: null);
                $refundRequest->setData('reviewed_at', (new \DateTime())->format('Y-m-d H:i:s'));
                $this->refundRequestResource->save($refundRequest);

                $this->expireMembership($membershipId, $customerId);

                $this->messageManager->addSuccessMessage(__('Refund request marked as reviewed. Membership has been expired.'));
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Could not update refund request: %1', $e->getMessage()));
        }

        return $redirect;
    }

    private function expireMembership(int $membershipId, int $customerId): void
    {
        try {
            $membership = $this->membershipRepository->getById($membershipId);
            $membership->setStatus(MembershipInterface::STATUS_EXPIRED);
            $membership->setAutoRenew(0);
            $this->membershipRepository->save($membership);
            $this->activateMembership->revertMemberGroup($customerId);
        } catch (\Exception $e) {
            $this->_logger->error('[CaliberNation] expireMembership failed for membership ' . $membershipId . ': ' . $e->getMessage());
        }
    }

    private function restoreMembershipToCancelled(int $membershipId, int $customerId): void
    {
        try {
            $membership = $this->membershipRepository->getById($membershipId);
            // Only restore if it is still expired (admin may have manually changed it).
            if ($membership->getStatus() === MembershipInterface::STATUS_EXPIRED) {
                $membership->setStatus(MembershipInterface::STATUS_CANCELLED);
                $this->membershipRepository->save($membership);
            }
        } catch (NoSuchEntityException) {
        }
    }
}
