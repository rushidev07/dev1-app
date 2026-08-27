<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model;

use Ahy\DiscountAlert\Api\Data\RunInterface;
use Ahy\DiscountAlert\Model\ResourceModel\Run as RunResource;
use Magento\Framework\Model\AbstractModel;

class Run extends AbstractModel implements RunInterface
{
    protected function _construct(): void
    {
        $this->_init(RunResource::class);
    }

    public function getRunId(): ?int
    {
        $value = $this->getData(self::RUN_ID);

        return $value === null ? null : (int) $value;
    }

    public function getToken(): string
    {
        return (string) $this->getData(self::TOKEN);
    }

    public function setToken(string $token): RunInterface
    {
        return $this->setData(self::TOKEN, $token);
    }

    public function getThreshold(): float
    {
        return (float) $this->getData(self::THRESHOLD);
    }

    public function setThreshold(float $threshold): RunInterface
    {
        return $this->setData(self::THRESHOLD, $threshold);
    }

    public function getProductCount(): int
    {
        return (int) $this->getData(self::PRODUCT_COUNT);
    }

    public function setProductCount(int $count): RunInterface
    {
        return $this->setData(self::PRODUCT_COUNT, $count);
    }

    public function getItemCount(): int
    {
        return (int) $this->getData(self::ITEM_COUNT);
    }

    public function setItemCount(int $count): RunInterface
    {
        return $this->setData(self::ITEM_COUNT, $count);
    }

    public function getNewCount(): int
    {
        return (int) $this->getData(self::NEW_COUNT);
    }

    public function setNewCount(int $count): RunInterface
    {
        return $this->setData(self::NEW_COUNT, $count);
    }

    public function getRemovedCount(): int
    {
        return (int) $this->getData(self::REMOVED_COUNT);
    }

    public function setRemovedCount(int $count): RunInterface
    {
        return $this->setData(self::REMOVED_COUNT, $count);
    }

    public function isEmailSent(): bool
    {
        return (bool) $this->getData(self::EMAIL_SENT);
    }

    public function hasChanges(): bool
    {
        return $this->getNewCount() > 0 || $this->getRemovedCount() > 0;
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    public function isTruncated(): bool
    {
        return $this->getItemCount() < $this->getProductCount();
    }
}
