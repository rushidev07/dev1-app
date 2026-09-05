<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Plugin\Webkul\SellerSubAccount;

use Ahy\ThemeCustomization\Model\SellerSubAccount\SubAccountCleanup;
use Magento\Customer\Controller\Adminhtml\Index\MassDelete;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

/**
 * Replacement for Webkul_SellerSubAccount::SellerMassDeleteControllerPlugin, which is
 * disabled in etc/adminhtml/di.xml. Webkul registered that one against massAction(),
 * a PROTECTED method — interception cannot reach it, so it has never fired at all, on
 * top of carrying the same undefined-property bugs as their single-delete plugin.
 *
 * We hook the public execute() instead and resolve the selected customer ids the same
 * way AbstractMassAction does, so grid mass-delete also detaches sub accounts.
 */
class CustomerMassDelete
{
    /**
     * @var SubAccountCleanup
     */
    private $subAccountCleanup;

    /**
     * @var Filter
     */
    private $filter;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SubAccountCleanup $subAccountCleanup
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        SubAccountCleanup $subAccountCleanup,
        Filter $filter,
        CollectionFactory $collectionFactory,
        LoggerInterface $logger
    ) {
        $this->subAccountCleanup = $subAccountCleanup;
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
    }

    /**
     * Detach sub accounts for every selected seller before the mass delete runs.
     *
     * @param MassDelete $subject
     * @param callable $proceed
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function aroundExecute(MassDelete $subject, callable $proceed)
    {
        foreach ($this->getSelectedCustomerIds() as $customerId) {
            $this->subAccountCleanup->detachSubAccounts((int) $customerId);
        }

        return $proceed();
    }

    /**
     * Resolve the customer ids the admin ticked in the grid.
     *
     * Filter::getCollection() throws when nothing is selected; the core controller
     * reports that to the admin, so here we just fall through to $proceed().
     *
     * @return array
     */
    private function getSelectedCustomerIds(): array
    {
        try {
            return $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
        } catch (\Exception $e) {
            $this->logger->info(
                '[Ahy_ThemeCustomization][SubAccountCleanup] could not resolve mass-delete'
                . ' selection, leaving it to the core controller: ' . $e->getMessage()
            );

            return [];
        }
    }
}
