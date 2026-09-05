<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel\RefundRequest\Grid;

use Ahy\CaliberNation\Model\ResourceModel\RefundRequest as RefundRequestResource;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid collection for the admin early cancellations listing.
 * Joins customer_entity to show email/name and supports fulltext search.
 */
class Collection extends SearchResult
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'ahy_caliber_nation_refund_request',
        $resourceModel = RefundRequestResource::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    protected function _initSelect()
    {
        parent::_initSelect();

        $this->getSelect()->joinLeft(
            ['ce' => $this->getTable('customer_entity')],
            'main_table.customer_id = ce.entity_id',
            [
                'customer_email' => 'ce.email',
                'customer_name'  => new \Zend_Db_Expr("TRIM(CONCAT(COALESCE(ce.firstname,''),' ',COALESCE(ce.lastname,'')))"),
            ]
        );

        $this->addFilterToMap('customer_email', 'ce.email');
        $this->addFilterToMap('customer_name', new \Zend_Db_Expr("TRIM(CONCAT(COALESCE(ce.firstname,''),' ',COALESCE(ce.lastname,'')))"));

        return $this;
    }

    public function addFullTextFilter(string $value): static
    {
        $conn = $this->getConnection();
        $like = '%' . $value . '%';
        $this->getSelect()->where(implode(' OR ', [
            $conn->quoteInto('ce.email LIKE ?', $like),
            $conn->quoteInto("TRIM(CONCAT(COALESCE(ce.firstname,''),' ',COALESCE(ce.lastname,''))) LIKE ?", $like),
        ]));
        return $this;
    }
}
