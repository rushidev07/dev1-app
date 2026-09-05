<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Api\Data;

/**
 * A single product recorded against a discount alert run, with the prices and
 * status captured at send time.
 *
 * @api
 */
interface RunItemInterface
{
    public const ITEM_ID        = 'item_id';
    public const RUN_ID         = 'run_id';
    public const PRODUCT_ID     = 'product_id';
    public const SKU            = 'sku';
    public const NAME           = 'name';
    public const PRICE          = 'price';
    public const SPECIAL_PRICE  = 'special_price';
    public const DISCOUNT_PCT   = 'discount_pct';
    public const STATUS_AT_SEND = 'status_at_send';
    public const IS_NEW         = 'is_new';
    public const DISABLED_AT    = 'disabled_at';
    public const DISABLED_BY    = 'disabled_by';
    public const REVERTED_AT    = 'reverted_at';
    public const REVERTED_BY    = 'reverted_by';

    public function getItemId(): ?int;

    public function getRunId(): int;

    public function getProductId(): int;

    public function getSku(): string;

    public function getName(): ?string;

    public function getPrice(): float;

    public function getSpecialPrice(): float;

    public function getDiscountPct(): float;

    /**
     * Product status at send time, kept so a bulk disable can be reversed.
     */
    public function getStatusAtSend(): ?int;

    /**
     * True when this product was not flagged by the previous run.
     */
    public function isNew(): bool;

    public function getDisabledAt(): ?string;

    public function getDisabledBy(): ?int;

    /**
     * When this product was reverted to MSRP from the review page, or null if it never was.
     */
    public function getRevertedAt(): ?string;

    public function getRevertedBy(): ?int;
}
