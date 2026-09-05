<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Related;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Magento\Catalog\Ui\DataProvider\Product\Related\AbstractDataProvider;

/**
 * Backs the "Manual Customers Also Bought" product-picker grid on the
 * product edit page - same mechanism as Magento's own Related/Up-Sell/
 * Cross-Sell grids, see AbstractDataProvider.
 */
class ManualCustomersAlsoBoughtDataProvider extends AbstractDataProvider
{
    protected function getLinkType()
    {
        return CreateManualCarouselLinkTypes::LINK_TYPE_CODE_MANUAL_CAB;
    }
}
