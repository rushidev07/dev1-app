<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Ui\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ApprovalStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'pending',  'label' => __('Pending')],
            ['value' => 'approved', 'label' => __('Approved')],
            ['value' => 'rejected', 'label' => __('Rejected')],
        ];
    }
}
