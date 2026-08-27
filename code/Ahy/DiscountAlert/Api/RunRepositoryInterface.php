<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Api;

use Ahy\DiscountAlert\Api\Data\RunInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * @api
 */
interface RunRepositoryInterface
{
    /**
     * Record a run together with its flagged products.
     *
     * @param  array<int, array<string, mixed>> $products     Rows from ProductCollectorInterface.
     * @param  int                              $matchedTotal Total matches before any cap.
     * @throws CouldNotSaveException
     */
    public function create(array $products, float $threshold, int $matchedTotal): RunInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $runId): RunInterface;

    /**
     * Resolve a run from an emailed link, validating the token and its lifetime.
     *
     * @throws LocalizedException When the run is unknown, the token does not match,
     *                            or the link has outlived its configured lifetime.
     */
    public function getByToken(int $runId, string $token): RunInterface;

    /**
     * @return int[] Product IDs recorded against this run.
     */
    public function getProductIds(int $runId): array;

    /**
     * @return int[] Product IDs this run flagged for the first time.
     */
    public function getNewProductIds(int $runId): array;

    /**
     * Record that this run produced an email.
     */
    public function markEmailSent(int $runId): void;

    /**
     * SKU and name as recorded for the given products, keyed by product ID.
     *
     * @param  int[] $productIds
     * @return array<int, array{sku: string, name: string|null}>
     */
    public function getProductLabels(int $runId, array $productIds): array;

    /**
     * Stamp the given products as disabled from the review page.
     *
     * @param  int[] $productIds
     * @return int   Rows affected.
     */
    public function markItemsDisabled(int $runId, array $productIds, ?int $adminUserId): int;

    /**
     * Stamp the given products as reverted to MSRP from the review page.
     *
     * @param  int[] $productIds
     * @return int   Rows affected.
     */
    public function markItemsReverted(int $runId, array $productIds, ?int $adminUserId): int;

    /**
     * Clear the revert stamp after a restore, so the products read as never reverted.
     *
     * @param  int[] $productIds
     * @return int   Rows affected.
     */
    public function clearItemsReverted(int $runId, array $productIds): int;

    /**
     * Special price recorded at send time for the given products, keyed by product ID.
     *
     * Products whose snapshot holds no usable special price are omitted, so a restore
     * never writes a zero or a null back onto the catalog.
     *
     * @param  int[] $productIds
     * @return array<int, float>
     */
    public function getSnapshotSpecialPrices(int $runId, array $productIds): array;
}
