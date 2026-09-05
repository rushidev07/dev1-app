<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Controller\Adminhtml\ExitCouponClaim;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Read-only grid of exit-popup coupon claims.
 *
 * There is deliberately no edit, delete or mass action: a claim is a record of
 * something that happened, and the coupon it names is owned by the sales rule.
 * Deleting a row here would not revoke the coupon, and editing one would only
 * make the grid disagree with salesrule_coupon - so neither is offered.
 */
class Index extends Action implements HttpGetActionInterface
{
    /**
     * Must match the resource id in etc/acl.xml - the base controller checks this
     * before dispatching.
     */
    public const ADMIN_RESOURCE = 'Ahy_PDPRevamp::exit_coupon_claims';

    private PageFactory $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Ahy_PDPRevamp::exit_coupon_claims');
        $resultPage->getConfig()->getTitle()->prepend(__('PDP Exit Popup Claims'));

        return $resultPage;
    }
}
