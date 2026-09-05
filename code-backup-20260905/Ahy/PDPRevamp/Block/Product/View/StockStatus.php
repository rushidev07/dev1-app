<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * PDP stock-status block (see Ahy\PDPRevamp\Plugin\StockStatusTemplatePlugin,
 * which swaps the "product.info.stockstatus" block's template to
 * product/view/stock-status.phtml). Only exists to give that template a
 * constructor-injected StockRegistryInterface instead of reaching for
 * ObjectManager::getInstance().
 */
class StockStatus extends Template
{
    private StockRegistryInterface $stockRegistry;

    public function __construct(
        Context $context,
        StockRegistryInterface $stockRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->stockRegistry = $stockRegistry;
    }

    public function getStockRegistry(): StockRegistryInterface
    {
        return $this->stockRegistry;
    }
}
