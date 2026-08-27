<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Api;

/**
 * Typed access to the module's admin configuration.
 *
 * All values live at default scope only (see etc/adminhtml/system.xml), so every
 * read is deliberately performed against the default scope: cron and CLI have no
 * meaningful "current store" and must not resolve differently from the admin UI.
 *
 * @api
 */
interface ConfigInterface
{
    public function isEnabled(): bool;

    /**
     * Minimum discount percentage a product must exceed to be flagged.
     */
    public function getThreshold(): float;

    /**
     * Primary (To) recipient. Empty string when unconfigured.
     */
    public function getRecipientEmail(): string;

    /**
     * @return string[] Valid CC addresses.
     */
    public function getCcEmails(): array;

    /**
     * @return string[] Valid BCC addresses.
     */
    public function getBccEmails(): array;

    /**
     * Hard cap on products collected per run (query LIMIT and CSV rows), bounding
     * memory and attachment size. Zero means no cap.
     */
    /**
     * When true, an alert is only emailed if the flagged list differs from the previous run.
     */
    public function isNotifyOnChangeOnly(): bool;

    public function getMaxProducts(): int;

    /**
     * How many products are rendered in the email body itself.
     */
    public function getEmailProductLimit(): int;

    /**
     * Days an emailed review link stays usable. Zero means it never expires.
     */
    public function getLinkLifetimeDays(): int;
}
