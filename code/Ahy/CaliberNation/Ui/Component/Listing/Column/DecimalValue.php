<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders a decimal grid column to 2 places.
 *
 * The underlying columns are DECIMAL(12,4), so raw values render as "20.0000" —
 * noise for money/percent figures an admin reads at a glance. Formatting is
 * display-only; the stored precision is untouched.
 */
class DecimalValue extends Column
{
    private const PRECISION = 2;

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $field = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            if (isset($item[$field]) && $item[$field] !== '' && is_numeric($item[$field])) {
                $item[$field] = number_format((float) $item[$field], self::PRECISION, '.', '');
            }
        }

        return $dataSource;
    }
}
