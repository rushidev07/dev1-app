<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel\SellerParticipation\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid collection for the admin Seller Participation listing.
 *
 * Joins the seller's shop name so admins can identify a row by something other than a
 * numeric id. Resolution order, because marketplace data is inconsistent:
 *   1. marketplace_userdata.shop_title  — the shop name the seller chose
 *   2. marketplace_userdata.shop_url    — always populated; the storefront slug
 *   3. customer firstname + lastname    — sellers who never completed their profile
 *   4. "Seller #<id>"                   — nothing else available
 *
 * NOTE: marketplace_userdata holds one row PER STORE VIEW (store_id 0 = admin scope,
 * where shop_title is usually NULL). Joining it directly would duplicate grid rows and
 * often show the blank admin-scope title, so the join goes through a derived table that
 * collapses each seller to a single row, preferring a non-empty title.
 */
class Collection extends SearchResult
{
    /**
     * Kept as a constant because it is needed twice: once as a SELECT column and again
     * in addFieldToFilter(), since MySQL cannot reference a SELECT alias in WHERE.
     */
    private const SELLER_NAME_EXPR = "COALESCE("
        . "seller.shop_title,"
        . "seller.shop_url,"
        . "NULLIF(TRIM(CONCAT(COALESCE(seller_customer.firstname, ''), ' ',"
        . " COALESCE(seller_customer.lastname, ''))), ''),"
        . "CONCAT('Seller #', main_table.seller_id)"
        . ")";

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'ahy_caliber_nation_seller_participation',
        $resourceModel = \Ahy\CaliberNation\Model\ResourceModel\SellerParticipation::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    protected function _initSelect()
    {
        parent::_initSelect();

        $connection = $this->getConnection();

        // One row per seller: MAX() skips NULL/'' titles, so a store-view title wins
        // over the empty admin-scope one without needing a store filter.
        $sellerData = $connection->select()
            ->from(
                ['mu' => $this->getTable('marketplace_userdata')],
                [
                    'seller_id'  => 'mu.seller_id',
                    'shop_title' => new \Zend_Db_Expr("MAX(NULLIF(TRIM(mu.shop_title), ''))"),
                    'shop_url'   => new \Zend_Db_Expr("MAX(NULLIF(TRIM(mu.shop_url), ''))"),
                ]
            )
            ->group('mu.seller_id');

        $this->getSelect()->joinLeft(
            ['seller' => $sellerData],
            'seller.seller_id = main_table.seller_id',
            []
        );

        // Customer name is the last resort for sellers with no marketplace profile.
        $this->getSelect()->joinLeft(
            ['seller_customer' => $this->getTable('customer_entity')],
            'seller_customer.entity_id = main_table.seller_id',
            []
        );

        $this->getSelect()->columns(['seller_name' => new \Zend_Db_Expr(self::SELLER_NAME_EXPR)]);

        return $this;
    }

    /**
     * seller_name is a SELECT alias, and MySQL does not allow aliases in WHERE — the
     * grid's text filter would fatal with "Unknown column 'seller_name' in where
     * clause". Redirect a filter on it to the underlying expression instead.
     *
     * @param string|array $field
     * @param mixed        $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'seller_name') {
            $field = new \Zend_Db_Expr(self::SELLER_NAME_EXPR);
        }

        return parent::addFieldToFilter($field, $condition);
    }

    public function addFullTextFilter(string $value): static
    {
        $conn = $this->getConnection();
        $this->getSelect()->where(
            $conn->quoteInto('(' . self::SELLER_NAME_EXPR . ') LIKE ?', '%' . $value . '%')
        );
        return $this;
    }
}
