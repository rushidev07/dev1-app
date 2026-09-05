<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Api\Data;

use Magento\Framework\Api\SearchResultsInterface;

interface MembershipSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface[]
     */
    public function getItems();

    /**
     * @param \Ahy\CaliberNation\Api\Data\MembershipInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
