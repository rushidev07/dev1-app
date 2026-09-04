<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Controller\Adminhtml\KeywordsImport;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Renders the Subcategory Keywords import form.
 */
class Index extends Action
{
    public const ADMIN_RESOURCE = 'Ahy_PlpRevamp::card_keywords_import';

    public function execute(): Page
    {
        /** @var Page $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu(self::ADMIN_RESOURCE);
        $result->getConfig()->getTitle()->prepend(__('PLP: Content Import'));

        return $result;
    }
}
