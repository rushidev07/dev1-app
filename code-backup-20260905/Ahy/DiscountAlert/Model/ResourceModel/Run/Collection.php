<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\ResourceModel\Run;

use Ahy\DiscountAlert\Api\Data\RunInterface;
use Ahy\DiscountAlert\Api\Data\RunItemInterface;
use Ahy\DiscountAlert\Model\ResourceModel\Run as RunResource;
use Ahy\DiscountAlert\Model\ResourceModel\RunItem as RunItemResource;
use Ahy\DiscountAlert\Model\Run as RunModel;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    public const FIELD_DISABLED_COUNT = 'disabled_count';

    protected function _construct(): void
    {
        $this->_init(RunModel::class, RunResource::class);
    }

    /**
     * Newest run first, which is what anyone opening the list wants to see.
     */
    public function addNewestFirst(): self
    {
        $this->setOrder(RunInterface::RUN_ID, self::SORT_ORDER_DESC);

        return $this;
    }

    /**
     * How many of each run's products have since been disabled from the review page.
     *
     * A correlated subquery rather than a join, so the run rows are not multiplied and no
     * GROUP BY is needed, which keeps getSize() straightforward.
     */
    public function addDisabledCount(): self
    {
        $itemTable = $this->getTable(RunItemResource::TABLE_NAME);

        $this->getSelect()->columns([
            self::FIELD_DISABLED_COUNT => new Expression(\sprintf(
                '(SELECT COUNT(*) FROM %s AS run_items'
                . ' WHERE run_items.%s = main_table.%s AND run_items.%s IS NOT NULL)',
                $itemTable,
                RunItemInterface::RUN_ID,
                RunInterface::RUN_ID,
                RunItemInterface::DISABLED_AT
            )),
        ]);

        return $this;
    }
}
