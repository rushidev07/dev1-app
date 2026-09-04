<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Api;

use Ahy\CaliberNation\Api\Data\MemberPriceInterface;
use Magento\Quote\Model\Quote\Item;

interface QuoteItemMemberPriceInterface
{
    /**
     * Member price for a cart line, or null when the line has none.
     *
     * Null means "render the normal price": program off, pricing off, visitor is
     * not an active member, line is the membership product or a child row, or the
     * product simply resolves no discount.
     *
     * When non-null, getRegularPrice() is what the line would cost without
     * membership and getMemberPrice() is what is actually being charged — the two
     * are computed from the same inputs as the ApplyMemberPrice observer, so they
     * can never disagree with the quote.
     *
     * @param Item $item
     * @return MemberPriceInterface|null
     */
    public function getForQuoteItem(Item $item): ?MemberPriceInterface;
}
