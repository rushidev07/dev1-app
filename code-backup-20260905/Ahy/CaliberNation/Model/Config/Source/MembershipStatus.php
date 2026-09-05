<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Config\Source;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Magento\Framework\Data\OptionSourceInterface;

class MembershipStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => MembershipInterface::STATUS_ACTIVE,          'label' => __('Active')],
            ['value' => MembershipInterface::STATUS_RENEWAL_PENDING, 'label' => __('Renewal Pending')],
            ['value' => MembershipInterface::STATUS_EXPIRED,         'label' => __('Expired')],
            ['value' => MembershipInterface::STATUS_CANCELLED,       'label' => __('Cancelled')],
        ];
    }
}
