<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

/**
 * PDP "Recently Viewed" block (see product/view/recently-viewed.phtml).
 * Only exists to give that template a constructor-injected
 * StoreManagerInterface instead of reaching for ObjectManager::getInstance().
 */
class RecentlyViewed extends Template
{
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->storeManager = $storeManager;
    }

    public function getStoreManager(): StoreManagerInterface
    {
        return $this->storeManager;
    }
}
