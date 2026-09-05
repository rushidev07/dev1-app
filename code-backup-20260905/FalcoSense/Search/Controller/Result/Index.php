<?php
declare(strict_types=1);

namespace FalcoSense\Search\Controller\Result;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Lightweight replacement for Magento\CatalogSearch\Controller\Result\Index.
 *
 * The original controller loads a full CatalogSearch product collection
 * (150k+ rows) before handing off to layout rendering — even when the
 * search.result block is removed from layout. This replacement skips that
 * entirely: it only sets the page title and returns the layout, letting
 * our Alpine.js block handle all product fetching via the platform API.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory      $pageFactory,
        private readonly RequestInterface $request,
    ) {}

    public function execute()
    {
        $query = trim((string) $this->request->getParam('q', ''));
        $page  = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__("Search results for: '%1'", $query));
        return $page;
    }
}
