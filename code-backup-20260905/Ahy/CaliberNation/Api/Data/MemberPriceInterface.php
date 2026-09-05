<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Api\Data;

/**
 * Effective member-price result for a product (read-only API DTO).
 */
interface MemberPriceInterface
{
    public const PRODUCT_ID    = 'product_id';
    public const REGULAR_PRICE = 'regular_price';
    public const MEMBER_PRICE  = 'member_price';
    public const DISCOUNT      = 'discount';
    public const HAS_DISCOUNT  = 'has_discount';
    public const IS_MEMBER     = 'is_member';

    /** @return int */
    public function getProductId(): int;

    /** @param int $productId @return void */
    public function setProductId(int $productId): void;

    /** @return float */
    public function getRegularPrice(): float;

    /** @param float $price @return void */
    public function setRegularPrice(float $price): void;

    /** @return float */
    public function getMemberPrice(): float;

    /** @param float $price @return void */
    public function setMemberPrice(float $price): void;

    /** @return float */
    public function getDiscount(): float;

    /** @param float $discount @return void */
    public function setDiscount(float $discount): void;

    /** @return bool */
    public function getHasDiscount(): bool;

    /** @param bool $hasDiscount @return void */
    public function setHasDiscount(bool $hasDiscount): void;

    /**
     * Whether the requesting customer is an active member (i.e. actually pays the
     * member price). False → the member_price is a public teaser.
     * @return bool
     */
    public function getIsMember(): bool;

    /** @param bool $isMember @return void */
    public function setIsMember(bool $isMember): void;
}
