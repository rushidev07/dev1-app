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
 * Grid collection for the admin refund requests listing.
 * Joins customer_entity (email/name) and admin_user (reviewer name).
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

        $this->getSelect()->joinLeft(
            ['au' => $this->getTable('admin_user')],
            'main_table.reviewed_by = au.user_id',
            [
                'reviewed_by_name' => new \Zend_Db_Expr("TRIM(CONCAT(COALESCE(au.firstname,''),' ',COALESCE(au.lastname,'')))"),
            ]
        );

        return $this;
    }
}
