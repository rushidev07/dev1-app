<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel\ExitCouponClaim;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Claims grid collection.
 *
 * Extends SearchResult rather than AbstractCollection so it satisfies
 * SearchResultInterface and can be handed straight to the generic
 * DataProvider in etc/adminhtml/di.xml - no bespoke DataProvider class needed.
 *
 * times_used is joined from salesrule_coupon rather than read from our own
 * is_redeemed: is_redeemed is our flag, set by Observer\MarkExitCouponRedeemed,
 * while times_used is Magento's own counter. Showing both makes a disagreement
 * between them visible instead of hidden, which matters because is_redeemed can
 * only ever be set going forward - claims made before that observer existed will
 * read 0 even if they were used.
 */
class Collection extends SearchResult
{
    /**
     * No _construct()/_init() override here on purpose.
     *
     * SearchResult's own constructor calls _init() using the mainTable and
     * resourceModel handed to it by DI (etc/adminhtml/di.xml), and it takes both
     * as required arguments with no defaults. Calling _init() again from
     * _construct() fights that wiring, and omitting the DI arguments is what
     * produced "Missing required argument $mainTable" on first load.
     */
    protected function _initSelect()
    {
        parent::_initSelect();

        $connection = $this->getConnection();

        $this->getSelect()->joinLeft(
            ['sc' => $this->getTable('salesrule_coupon')],
            'sc.code = main_table.coupon_code',
            ['times_used' => 'sc.times_used']
        );

        // Product and customer names are what an admin actually recognises; the
        // ids on their own are not useful in a grid.
        $this->getSelect()->joinLeft(
            ['ce' => $this->getTable('customer_entity')],
            'ce.entity_id = main_table.customer_id',
            [
                'customer_name' => $connection->getConcatSql(
                    ['ce.firstname', "' '", 'ce.lastname']
                ),
            ]
        );

        $this->getSelect()->joinLeft(
            ['cpe' => $this->getTable('catalog_product_entity')],
            'cpe.entity_id = main_table.product_id',
            ['product_sku' => 'cpe.sku']
        );

        return $this;
    }
}
