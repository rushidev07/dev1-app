<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel\Membership\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid collection for the admin membership listing. Joins customer_entity so the
 * grid can show the member's email/name alongside the membership fields.
 */
class Collection extends SearchResult
{
    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'ahy_caliber_nation_membership',
        $resourceModel = \Ahy\CaliberNation\Model\ResourceModel\Membership::class
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
        // Map filter fields to real expressions so WHERE clauses don't use SELECT aliases
        $this->addFilterToMap('customer_email', 'ce.email');
        $this->addFilterToMap('customer_name', new \Zend_Db_Expr("TRIM(CONCAT(COALESCE(ce.firstname,''),' ',COALESCE(ce.lastname,'')))"));
        return $this;
    }

    public function addFullTextFilter(string $value): static
    {
        $conn = $this->getConnection();
        $like  = '%' . $value . '%';
        $this->getSelect()->where(implode(' OR ', [
            $conn->quoteInto('ce.email LIKE ?', $like),
            $conn->quoteInto("TRIM(CONCAT(COALESCE(ce.firstname,''),' ',COALESCE(ce.lastname,''))) LIKE ?", $like),
            $conn->quoteInto('main_table.status LIKE ?', $like),
            $conn->quoteInto('main_table.tier LIKE ?', $like),
        ]));
        return $this;
    }
}
