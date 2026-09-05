<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Controller\Index;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
    ) {}

    public function execute(): \Magento\Framework\View\Result\Page
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Featured Brands'));
        return $page;
    }
}
