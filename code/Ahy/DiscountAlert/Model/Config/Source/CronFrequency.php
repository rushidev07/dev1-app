<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Schedule choices for the alert.
 *
 * Magento evaluates cron expressions in the timezone configured under General > Locale
 * Options, not the server clock, so these times follow the store's own timezone.
 */
class CronFrequency implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '0 9 * * 1',     'label' => 'Once a week (Monday at 9:00 AM)'],
            ['value' => '0 9 * * 1,4',   'label' => 'Twice a week (Mon & Thu at 9:00 AM)'],
            ['value' => '0 9 * * 1,3,5', 'label' => 'Three times a week (Mon, Wed, Fri at 9:00 AM)'],
            ['value' => '0 9 * * *',     'label' => 'Daily (9:00 AM)'],
            ['value' => '0 9,13,17,21 * * *', 'label' => 'Four times a day (9:00 AM, 1:00 PM, 5:00 PM, 9:00 PM)'],
        ];
    }
}
