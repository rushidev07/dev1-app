<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Controller\Adminhtml\Run;

/**
 * Removes the special price from products chosen on the review grid, leaving MSRP as the
 * only price on the storefront.
 *
 * The MSRP is already stored - the FlxPoint import writes it into `price` and puts the
 * vendor's MAP into `special_price` - so this action has nothing to look up or calculate.
 * It clears the MAP and MSRP stands on its own.
 *
 * The removed value stays available in the run's snapshot, so RestoreSpecialPrice can put
 * it back if the change was not wanted.
 */
class RevertToMsrp extends AbstractPriceAction
{
    protected function applyTo(int $runId, array $productIds, ?int $adminUserId): void
    {
        $this->priceUpdater->remove($productIds);
        $this->runRepository->markItemsReverted($runId, $productIds, $adminUserId);
    }

    protected function getPastTenseVerb(): string
    {
        return (string) __('reverted to MSRP');
    }

    protected function getActionName(): string
    {
        return 'RevertToMsrp';
    }
}
