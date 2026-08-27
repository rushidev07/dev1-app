<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Search;

use FalcoSense\Search\Helper\Data;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces Klevu\Search\Controller\Index\Index.
 * Renders our native search results page and logs the query to the platform.
 */
class Index extends Action
{
    private const LOG_FILE = BP . '/var/log/smartsearch-analytics.log';

    public function __construct(
        Context                              $context,
        private readonly PageFactory         $pageFactory,
        private readonly Data                $helper,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface     $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $queryText = trim((string) $this->getRequest()->getParam('q', ''));

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__("Search results for: '%1'", $queryText));
        return $page;
    }
}
