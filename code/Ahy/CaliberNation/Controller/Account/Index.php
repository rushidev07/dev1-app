<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Controller\Account;

use Ahy\CaliberNation\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly CustomerSession $customerSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly UrlInterface $url,
        private readonly Config $config
    ) {}

    public function execute(): ResultInterface
    {
        // Program off → this page doesn't exist; send the customer to their dashboard.
        if (!$this->config->isEnabled()) {
            return $this->redirectFactory->create()->setPath('customer/account');
        }

        if (!$this->customerSession->isLoggedIn()) {
            return $this->redirectFactory->create()->setPath(
                'customer/account/login',
                ['_query' => ['referer' => base64_encode($this->url->getCurrentUrl())]]
            );
        }

        return $this->pageFactory->create();
    }
}
