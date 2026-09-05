<?php
declare(strict_types=1);

namespace Ahy\PlpRevamp\Observer\Frontend\Category;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;

class AddSubcategoryLayoutHandle implements ObserverInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly PageConfig $pageConfig
    ) {}

    public function execute(Observer $observer): void
    {
        $fullActionName = $observer->getEvent()->getFullActionName();
        if ($fullActionName !== 'catalog_category_view') {
            return;
        }

        /** @var \Magento\Catalog\Model\Category|null $category */
        $category = $this->registry->registry('current_category');
        if (!$category) {
            return;
        }

        if ((int)$category->getData('ahy_use_subcategory_layout') !== 1) {
            return;
        }

        $observer->getEvent()->getLayout()->getUpdate()->addHandle('ahy_subcategory_layout_enabled');

        // Leaf category (no children) — force 1-column so empty Magento sidebar space is removed
        if (empty(trim((string)$category->getChildren()))) {
            $this->pageConfig->setPageLayout('1column');
        }
    }
}