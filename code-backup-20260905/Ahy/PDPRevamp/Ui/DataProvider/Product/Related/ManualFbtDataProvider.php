<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Related;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualFbtLinkType;
use Magento\Catalog\Ui\DataProvider\Product\Related\AbstractDataProvider;

/**
 * Backs the "Frequently Bought Together (Manual)" product-picker grid on the
 * product edit page - same mechanism as Magento's own Related/Up-Sell/Cross-Sell
 * grids, see AbstractDataProvider.
 *
 * These picks are the FBT section's fallback: used when purchase history yields
 * nothing, or on any product with pdp_fbt_force_manual set.
 */
class ManualFbtDataProvider extends AbstractDataProvider
{
    protected function getLinkType()
    {
        return CreateManualFbtLinkType::LINK_TYPE_CODE_MANUAL_FBT;
    }
}
