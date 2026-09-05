<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\ResourceModel\MemberPriceRule\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * Grid collection for the admin Category Level Discounts listing.
 *
 * Joins the category name so a rule reads as "Air Guns" rather than a bare id. Falls
 * back to "Category #<id>" when the category has been deleted, so an orphaned rule is
 * still visible instead of showing a blank cell.
 *
 * NOTE: category names live in catalog_category_entity_varchar with one row PER STORE
 * VIEW. The join is restricted to store_id = 0 (admin scope) — joining unrestricted
 * would duplicate a grid row once per store view.
 */
class Collection extends SearchResult
{
    /**
     * Kept as a constant because it is needed twice: as a SELECT column and again in
     * addFieldToFilter(), since MySQL cannot reference a SELECT alias in WHERE.
     */
    private const CATEGORY_NAME_EXPR =
        "COALESCE(NULLIF(TRIM(cat_name.value), ''), CONCAT('Category #', main_table.target_id))";

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        $mainTable = 'ahy_caliber_nation_member_price_rule',
        $resourceModel = \Ahy\CaliberNation\Model\ResourceModel\MemberPriceRule::class
    ) {
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $mainTable, $resourceModel);
    }

    protected function _initSelect()
    {
        parent::_initSelect();

        $connection = $this->getConnection();

        // Category "name" attribute id — joined rather than sub-queried so the SQL
        // stays readable and portable.
        $nameAttributeId = (int) $connection->fetchOne(
            $connection->select()
                ->from(['a' => $this->getTable('eav_attribute')], ['a.attribute_id'])
                ->join(
                    ['et' => $this->getTable('eav_entity_type')],
                    'et.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('a.attribute_code = ?', 'name')
                ->where('et.entity_type_code = ?', 'catalog_category')
        );

        $this->getSelect()->joinLeft(
            ['cat_name' => $this->getTable('catalog_category_entity_varchar')],
            $connection->quoteInto(
                'cat_name.entity_id = main_table.target_id'
                . ' AND cat_name.attribute_id = ?'
                . ' AND cat_name.store_id = 0',
                $nameAttributeId
            ),
            []
        );

        $this->getSelect()->columns(['category_name' => new \Zend_Db_Expr(self::CATEGORY_NAME_EXPR)]);

        return $this;
    }

    /**
     * category_name is a SELECT alias, and MySQL does not allow aliases in WHERE — the
     * grid's text filter would fatal with "Unknown column 'category_name' in where
     * clause". Redirect a filter on it to the underlying expression instead.
     *
     * @param string|array $field
     * @param mixed        $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field === 'category_name') {
            $field = new \Zend_Db_Expr(self::CATEGORY_NAME_EXPR);
        }

        return parent::addFieldToFilter($field, $condition);
    }

    public function addFullTextFilter(string $value): static
    {
        $conn = $this->getConnection();
        $this->getSelect()->where(
            $conn->quoteInto('(' . self::CATEGORY_NAME_EXPR . ') LIKE ?', '%' . $value . '%')
        );
        return $this;
    }
}
