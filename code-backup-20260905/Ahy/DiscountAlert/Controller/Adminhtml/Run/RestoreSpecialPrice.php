<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Controller\Adminhtml\Run;

use Magento\Framework\Exception\LocalizedException;

/**
 * Puts back the special price a Revert to MSRP removed, using the value the run recorded
 * at send time.
 *
 * The snapshot is the only source: once the special price has been cleared from the
 * catalog there is nowhere else to read the original from.
 */
class RestoreSpecialPrice extends AbstractPriceAction
{
    /**
     * @throws LocalizedException When no snapshot price survives for any chosen product.
     */
    protected function applyTo(int $runId, array $productIds, ?int $adminUserId): void
    {
        $prices = $this->runRepository->getSnapshotSpecialPrices($runId, $productIds);

        if (!$prices) {
            throw new LocalizedException(
                __('No recorded special price is available for the selected products.')
            );
        }

        $missing = \array_diff($productIds, \array_keys($prices));

        if ($missing) {
            // Restoring what is available beats refusing the whole batch, but the gap is
            // worth a line in the log rather than passing silently.
            $this->logger->warning(\sprintf(
                'Run #%d: %d product(s) had no recorded special price and were left on MSRP: %s',
                $runId,
                \count($missing),
                \implode(', ', $missing)
            ));
        }

        $restored = \array_keys($prices);

        $this->priceUpdater->restore($prices);
        $this->runRepository->clearItemsReverted($runId, $restored);
    }

    protected function getPastTenseVerb(): string
    {
        return (string) __('restored to their special price');
    }

    protected function getActionName(): string
    {
        return 'RestoreSpecialPrice';
    }
}
