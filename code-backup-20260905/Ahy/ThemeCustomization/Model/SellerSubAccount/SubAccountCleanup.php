<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Model\SellerSubAccount;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as SellerCollectionFactory;
use Webkul\SellerSubAccount\Api\SubAccountRepositoryInterface;
use Webkul\SellerSubAccount\Model\ResourceModel\SubAccount\CollectionFactory as SubAccountCollectionFactory;

/**
 * Detaches a seller's sub accounts before the seller's own customer record is deleted.
 *
 * This is the working replacement for the logic Webkul buried inside
 * Webkul\SellerSubAccount\Plugin\Controller\Seller\Delete / MassDelete. Those two
 * plugin classes are copy-pasted controller code — they call $this->resultRedirectFactory,
 * $this->_formKeyValidator, $this->getRequest(), $this->initCurrentCustomer() and
 * $this->_customerRepository, none of which exist on a plugin class — so they fatal on
 * their first line and customer deletion never runs. Both are disabled in
 * etc/adminhtml/di.xml and replaced by the Ahy plugins that call into this service.
 *
 * Behaviour preserved from Webkul: each sub account customer is moved back to the
 * default customer group with auto group change re-enabled, then the
 * marketplace_sub_accounts row is removed.
 */
class SubAccountCleanup
{
    /**
     * @var SellerCollectionFactory
     */
    private $sellerCollectionFactory;

    /**
     * @var SubAccountCollectionFactory
     */
    private $subAccountCollectionFactory;

    /**
     * @var SubAccountRepositoryInterface
     */
    private $subAccountRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var GroupManagementInterface
     */
    private $groupManagement;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SellerCollectionFactory $sellerCollectionFactory
     * @param SubAccountCollectionFactory $subAccountCollectionFactory
     * @param SubAccountRepositoryInterface $subAccountRepository
     * @param CustomerRepositoryInterface $customerRepository
     * @param GroupManagementInterface $groupManagement
     * @param LoggerInterface $logger
     */
    public function __construct(
        SellerCollectionFactory $sellerCollectionFactory,
        SubAccountCollectionFactory $subAccountCollectionFactory,
        SubAccountRepositoryInterface $subAccountRepository,
        CustomerRepositoryInterface $customerRepository,
        GroupManagementInterface $groupManagement,
        LoggerInterface $logger
    ) {
        $this->sellerCollectionFactory = $sellerCollectionFactory;
        $this->subAccountCollectionFactory = $subAccountCollectionFactory;
        $this->subAccountRepository = $subAccountRepository;
        $this->customerRepository = $customerRepository;
        $this->groupManagement = $groupManagement;
        $this->logger = $logger;
    }

    /**
     * Detach every sub account belonging to the given seller.
     *
     * Never throws: a failure here must not block the customer delete the admin asked
     * for. Anything that goes wrong is logged for follow-up.
     *
     * @param int $customerId
     *
     * @return void
     */
    public function detachSubAccounts(int $customerId): void
    {
        try {
            if (!$customerId || !$this->isSeller($customerId)) {
                return;
            }

            $subAccounts = $this->subAccountCollectionFactory->create()
                ->addFieldToFilter('seller_id', $customerId);

            foreach ($subAccounts as $subAccount) {
                $this->releaseSubAccountCustomer((int) $subAccount->getCustomerId());
                $this->subAccountRepository->delete($subAccount);
            }
        } catch (\Exception $e) {
            $this->logger->critical(
                '[Ahy_ThemeCustomization][SubAccountCleanup] failed for seller ' . $customerId,
                ['exception' => $e]
            );
        }
    }

    /**
     * Is the customer a marketplace seller?
     *
     * @param int $customerId
     *
     * @return bool
     */
    private function isSeller(int $customerId): bool
    {
        $sellers = $this->sellerCollectionFactory->create()
            ->addFieldToFilter('seller_id', $customerId)
            ->addFieldToFilter('store_id', 0);

        foreach ($sellers as $seller) {
            if ($seller->getIsSeller()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move a sub account customer back to the default group and re-enable auto group change.
     *
     * @param int $subAccountCustomerId
     *
     * @return void
     */
    private function releaseSubAccountCustomer(int $subAccountCustomerId): void
    {
        if (!$subAccountCustomerId) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById($subAccountCustomerId);
            $customer->setGroupId($this->groupManagement->getDefaultGroup()->getId());
            // CustomerInterface has no setDisableAutoGroupChange() — it is an EAV attribute.
            $customer->setCustomAttribute('disable_auto_group_change', 0);
            $this->customerRepository->save($customer);
        } catch (NoSuchEntityException $e) {
            $this->logger->info(
                '[Ahy_ThemeCustomization][SubAccountCleanup] sub account customer '
                . $subAccountCustomerId . ' no longer exists, skipping'
            );
        }
    }
}
