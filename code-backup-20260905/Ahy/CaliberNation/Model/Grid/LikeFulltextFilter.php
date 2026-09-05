<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Grid;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\View\Element\UiComponent\DataProvider\FulltextFilter;

/**
 * Replaces Magento's MATCH…AGAINST fulltext applier for grids whose main tables
 * have no FULLTEXT index. When the collection defines addFullTextFilter() we call
 * it (LIKE-based); otherwise we fall through to the standard FULLTEXT behavior.
 */
class LikeFulltextFilter extends FulltextFilter
{
    public function apply(Collection $collection, Filter $filter): void
    {
        if (method_exists($collection, 'addFullTextFilter')) {
            $collection->addFullTextFilter($filter->getValue());
            return;
        }

        parent::apply($collection, $filter);
    }
}
