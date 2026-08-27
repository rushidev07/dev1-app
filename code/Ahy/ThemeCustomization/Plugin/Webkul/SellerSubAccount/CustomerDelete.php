<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Plugin\Webkul\SellerSubAccount;

use Ahy\ThemeCustomization\Model\SellerSubAccount\SubAccountCleanup;
use Magento\Customer\Controller\Adminhtml\Index\Delete;

/**
 * Replacement for Webkul_SellerSubAccount::SellerDeleteControllerPlugin, which is
 * disabled in etc/adminhtml/di.xml because it fatals on every admin customer delete
 * (it references controller-only properties from inside a plugin class).
 *
 * Unlike Webkul's version this does NOT re-implement the delete. It only detaches the
 * seller's sub accounts, then hands off to the core controller, which keeps ownership
 * of form key validation, the delete itself, the success message and the redirect.
 */
class CustomerDelete
{
    /**
     * @var SubAccountCleanup
     */
    private $subAccountCleanup;

    /**
     * @param SubAccountCleanup $subAccountCleanup
     */
    public function __construct(SubAccountCleanup $subAccountCleanup)
    {
        $this->subAccountCleanup = $subAccountCleanup;
    }

    /**
     * Detach the seller's sub accounts before the customer row goes away.
     *
     * @param Delete $subject
     * @param callable $proceed
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function aroundExecute(Delete $subject, callable $proceed)
    {
        $request = $subject->getRequest();

        // Mirror the core controller's own guard: only act on a genuine POST delete,
        // so a stale GET cannot orphan sub accounts without deleting the seller.
        if ($request->isPost()) {
            $this->subAccountCleanup->detachSubAccounts((int) $request->getParam('id'));
        }

        return $proceed();
    }
}
