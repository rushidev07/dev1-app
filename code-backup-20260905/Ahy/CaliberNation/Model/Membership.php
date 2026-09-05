<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Magento\Framework\Model\AbstractModel;

class Membership extends AbstractModel implements MembershipInterface
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(\Ahy\CaliberNation\Model\ResourceModel\Membership::class);
    }

    /**
     * @inheritDoc
     */
    public function getEntityId()
    {
        return $this->getData(self::ENTITY_ID) === null
            ? null
            : (int) $this->getData(self::ENTITY_ID);
    }

    /**
     * @inheritDoc
     */
    public function setEntityId($entityId)
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * @inheritDoc
     */
    public function getCustomerId()
    {
        return $this->getData(self::CUSTOMER_ID) === null
            ? null
            : (int) $this->getData(self::CUSTOMER_ID);
    }

    /**
     * @inheritDoc
     */
    public function setCustomerId($customerId)
    {
        return $this->setData(self::CUSTOMER_ID, $customerId);
    }

    /**
     * @inheritDoc
     */
    public function getStatus()
    {
        return $this->getData(self::STATUS);
    }

    /**
     * @inheritDoc
     */
    public function setStatus($status)
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * @inheritDoc
     */
    public function getTier()
    {
        return $this->getData(self::TIER);
    }

    /**
     * @inheritDoc
     */
    public function setTier($tier)
    {
        return $this->setData(self::TIER, $tier);
    }

    /**
     * @inheritDoc
     */
    public function getStartDate()
    {
        return $this->getData(self::START_DATE);
    }

    /**
     * @inheritDoc
     */
    public function setStartDate($startDate)
    {
        return $this->setData(self::START_DATE, $startDate);
    }

    /**
     * @inheritDoc
     */
    public function getRenewalDate()
    {
        return $this->getData(self::RENEWAL_DATE);
    }

    /**
     * @inheritDoc
     */
    public function setRenewalDate($renewalDate)
    {
        return $this->setData(self::RENEWAL_DATE, $renewalDate);
    }

    /**
     * @inheritDoc
     */
    public function getAutoRenew()
    {
        return (int) $this->getData(self::AUTO_RENEW);
    }

    /**
     * @inheritDoc
     */
    public function setAutoRenew($autoRenew)
    {
        return $this->setData(self::AUTO_RENEW, $autoRenew);
    }

    /**
     * @inheritDoc
     */
    public function getPaymentTokenId()
    {
        return $this->getData(self::PAYMENT_TOKEN_ID) === null
            ? null
            : (int) $this->getData(self::PAYMENT_TOKEN_ID);
    }

    /**
     * @inheritDoc
     */
    public function setPaymentTokenId($paymentTokenId)
    {
        return $this->setData(self::PAYMENT_TOKEN_ID, $paymentTokenId);
    }

    /**
     * @inheritDoc
     */
    public function getIsTrial()
    {
        return (int) $this->getData(self::IS_TRIAL);
    }

    /**
     * @inheritDoc
     */
    public function setIsTrial($isTrial)
    {
        return $this->setData(self::IS_TRIAL, $isTrial);
    }

    /**
     * @inheritDoc
     */
    public function getLifetimeSavings()
    {
        return (float) $this->getData(self::LIFETIME_SAVINGS);
    }

    /**
     * @inheritDoc
     */
    public function setLifetimeSavings($lifetimeSavings)
    {
        return $this->setData(self::LIFETIME_SAVINGS, $lifetimeSavings);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt()
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setCreatedAt($createdAt)
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt()
    {
        return $this->getData(self::UPDATED_AT);
    }

    /**
     * @inheritDoc
     */
    public function setUpdatedAt($updatedAt)
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
