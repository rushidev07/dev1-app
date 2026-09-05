<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service;

/**
 * Outcome of one dispatch attempt. Immutable value object shared by cron and CLI so both
 * report the same facts.
 */
class AlertResult
{
    public const STATUS_SENT      = 'sent';
    public const STATUS_DRY_RUN   = 'dry_run';
    public const STATUS_SKIPPED   = 'skipped';
    public const STATUS_NO_CHANGE = 'no_change';

    /**
     * @param array<int, array<string, mixed>> $products Collected rows (dry runs included).
     */
    public function __construct(
        private readonly string $status,
        private readonly float $threshold = 0.0,
        private readonly int $matchedTotal = 0,
        private readonly int $collectedCount = 0,
        private readonly string $recipient = '',
        private readonly ?int $runId = null,
        private readonly string $reviewUrl = '',
        private readonly string $reason = '',
        private readonly array $products = [],
        private readonly int $newCount = 0,
        private readonly int $removedCount = 0
    ) {}

    /**
     * Products flagged for the first time in this run.
     */
    public function getNewCount(): int
    {
        return $this->newCount;
    }

    /**
     * Products the previous run flagged that have since dropped off.
     */
    public function getRemovedCount(): int
    {
        return $this->removedCount;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function getThreshold(): float
    {
        return $this->threshold;
    }

    /**
     * Products matching the threshold, before the collection cap.
     */
    public function getMatchedTotal(): int
    {
        return $this->matchedTotal;
    }

    /**
     * Products actually collected, recorded and written to the CSV.
     */
    public function getCollectedCount(): int
    {
        return $this->collectedCount;
    }

    public function isTruncated(): bool
    {
        return $this->collectedCount < $this->matchedTotal;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function getRunId(): ?int
    {
        return $this->runId;
    }

    public function getReviewUrl(): string
    {
        return $this->reviewUrl;
    }

    /**
     * Human-readable explanation when nothing was sent.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(): array
    {
        return $this->products;
    }
}
