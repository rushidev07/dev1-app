<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Api\Data;

/**
 * One execution of the discount alert: what was flagged, when, and under which
 * threshold. Persisted so the emailed review link can resolve exactly the set of
 * products that alert was about.
 *
 * @api
 */
interface RunInterface
{
    public const RUN_ID        = 'run_id';
    public const TOKEN         = 'token';
    public const THRESHOLD     = 'threshold';
    public const PRODUCT_COUNT = 'product_count';
    public const ITEM_COUNT    = 'item_count';
    public const NEW_COUNT     = 'new_count';
    public const REMOVED_COUNT = 'removed_count';
    public const EMAIL_SENT    = 'email_sent';
    public const CREATED_AT    = 'created_at';

    public function getRunId(): ?int;

    /**
     * Random token that authorizes the emailed deep link for this run.
     */
    public function getToken(): string;

    public function setToken(string $token): self;

    public function getThreshold(): float;

    public function setThreshold(float $threshold): self;

    /**
     * Total products that matched, before any collection cap was applied.
     */
    public function getProductCount(): int;

    public function setProductCount(int $count): self;

    /**
     * Products actually recorded for this run (equals the product count unless the
     * configured cap truncated the result set).
     */
    public function getItemCount(): int;

    public function setItemCount(int $count): self;

    /**
     * Products flagged for the first time, compared with the previous run.
     */
    public function getNewCount(): int;

    public function setNewCount(int $count): self;

    /**
     * Products the previous run flagged that are no longer above the threshold.
     */
    public function getRemovedCount(): int;

    public function setRemovedCount(int $count): self;

    /**
     * True when this run produced an email.
     */
    public function isEmailSent(): bool;

    /**
     * True when the flagged list differs from the previous run, in either direction.
     */
    public function hasChanges(): bool;

    /**
     * UTC creation timestamp, as stored.
     */
    public function getCreatedAt(): ?string;

    /**
     * True when the collection cap dropped products from this run.
     */
    public function isTruncated(): bool;
}
