<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model;

use Ahy\DiscountAlert\Api\Data\RunItemInterface;
use Ahy\DiscountAlert\Model\ResourceModel\RunItem as RunItemResource;
use Magento\Framework\Model\AbstractModel;

class RunItem extends AbstractModel implements RunItemInterface
{
    protected function _construct(): void
    {
        $this->_init(RunItemResource::class);
    }

    public function getItemId(): ?int
    {
        $value = $this->getData(self::ITEM_ID);

        return $value === null ? null : (int) $value;
    }

    public function getRunId(): int
    {
        return (int) $this->getData(self::RUN_ID);
    }

    public function getProductId(): int
    {
        return (int) $this->getData(self::PRODUCT_ID);
    }

    public function getSku(): string
    {
        return (string) $this->getData(self::SKU);
    }

    public function getName(): ?string
    {
        $value = $this->getData(self::NAME);

        return $value === null ? null : (string) $value;
    }

    public function getPrice(): float
    {
        return (float) $this->getData(self::PRICE);
    }

    public function getSpecialPrice(): float
    {
        return (float) $this->getData(self::SPECIAL_PRICE);
    }

    public function getDiscountPct(): float
    {
        return (float) $this->getData(self::DISCOUNT_PCT);
    }

    public function getStatusAtSend(): ?int
    {
        $value = $this->getData(self::STATUS_AT_SEND);

        return $value === null ? null : (int) $value;
    }

    public function isNew(): bool
    {
        return (bool) $this->getData(self::IS_NEW);
    }

    public function getDisabledAt(): ?string
    {
        $value = $this->getData(self::DISABLED_AT);

        return $value === null ? null : (string) $value;
    }

    public function getDisabledBy(): ?int
    {
        $value = $this->getData(self::DISABLED_BY);

        return $value === null ? null : (int) $value;
    }

    public function getRevertedAt(): ?string
    {
        $value = $this->getData(self::REVERTED_AT);

        return $value === null ? null : (string) $value;
    }

    public function getRevertedBy(): ?int
    {
        $value = $this->getData(self::REVERTED_BY);

        return $value === null ? null : (int) $value;
    }
}
