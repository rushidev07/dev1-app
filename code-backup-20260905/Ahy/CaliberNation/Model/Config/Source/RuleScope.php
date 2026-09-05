<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Config\Source;

use Ahy\CaliberNation\Model\MemberPriceRule;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Scope options for a member price rule (category or product).
 */
class RuleScope implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        // Product-scope discounts now live on the product edit form
        // (caliber_member_discount_* attributes). The rules grid handles categories.
        return [
            ['value' => MemberPriceRule::SCOPE_CATEGORY, 'label' => __('Category')],
        ];
    }
}
