<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Ahy\CaliberNation\Api\Data\MemberPriceInterface;
use Magento\Framework\DataObject;

/**
 * DTO impl for MemberPriceInterface (extends DataObject for API serialization).
 */
class MemberPrice extends DataObject implements MemberPriceInterface
{
    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function setProductId(int $productId): void
    {
        $this->setData(self::PRODUCT_ID, $productId);
    }

    public function getRegularPrice(): float
    {
        return (float) $this->getData(self::REGULAR_PRICE);
    }

    public function setRegularPrice(float $price): void
    {
        $this->setData(self::REGULAR_PRICE, $price);
    }

    public function getMemberPrice(): float
    {
        return (float) $this->getData(self::MEMBER_PRICE);
    }

    public function setMemberPrice(float $price): void
    {
        $this->setData(self::MEMBER_PRICE, $price);
    }

    public function getDiscount(): float
    {
        return (float) $this->getData(self::DISCOUNT);
    }

    public function setDiscount(float $discount): void
    {
        $this->setData(self::DISCOUNT, $discount);
    }

    public function getHasDiscount(): bool
    {
        return (bool) $this->getData(self::HAS_DISCOUNT);
    }

    public function setHasDiscount(bool $hasDiscount): void
    {
        $this->setData(self::HAS_DISCOUNT, $hasDiscount);
    }

    public function getIsMember(): bool
    {
        return (bool) $this->getData(self::IS_MEMBER);
    }

    public function setIsMember(bool $isMember): void
    {
        $this->setData(self::IS_MEMBER, $isMember);
    }
}
