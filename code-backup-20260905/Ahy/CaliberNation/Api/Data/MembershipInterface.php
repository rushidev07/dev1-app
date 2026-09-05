<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Api\Data;

interface MembershipInterface
{
    /**#@+
     * Field name constants.
     */
    public const ENTITY_ID = 'entity_id';
    public const CUSTOMER_ID = 'customer_id';
    public const STATUS = 'status';
    public const TIER = 'tier';
    public const START_DATE = 'start_date';
    public const RENEWAL_DATE = 'renewal_date';
    public const AUTO_RENEW = 'auto_renew';
    public const PAYMENT_TOKEN_ID = 'payment_token_id';
    public const IS_TRIAL = 'is_trial';
    public const LIFETIME_SAVINGS = 'lifetime_savings';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    /**#@-*/

    /**#@+
     * Membership status values — single source of truth.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RENEWAL_PENDING = 'renewal_pending';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';
    /**#@-*/

    /**#@+
     * Tier values. Only the annual tier is offered.
     */
    public const TIER_ANNUAL = 'annual';

    public const VALID_TIERS = [
        self::TIER_ANNUAL,
    ];
    /**#@-*/

    /**
     * @return int|null
     */
    public function getEntityId();

    /**
     * @param int $entityId
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setEntityId($entityId);

    /**
     * @return int|null
     */
    public function getCustomerId();

    /**
     * @param int $customerId
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setCustomerId($customerId);

    /**
     * @return string|null
     */
    public function getStatus();

    /**
     * @param string $status
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setStatus($status);

    /**
     * @return string|null
     */
    public function getTier();

    /**
     * @param string $tier
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setTier($tier);

    /**
     * @return string|null
     */
    public function getStartDate();

    /**
     * @param string|null $startDate
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setStartDate($startDate);

    /**
     * @return string|null
     */
    public function getRenewalDate();

    /**
     * @param string|null $renewalDate
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setRenewalDate($renewalDate);

    /**
     * @return int
     */
    public function getAutoRenew();

    /**
     * @param int $autoRenew
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setAutoRenew($autoRenew);

    /**
     * @return int|null
     */
    public function getPaymentTokenId();

    /**
     * @param int|null $paymentTokenId
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setPaymentTokenId($paymentTokenId);

    /**
     * @return int
     */
    public function getIsTrial();

    /**
     * @param int $isTrial
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setIsTrial($isTrial);

    /**
     * @return float
     */
    public function getLifetimeSavings();

    /**
     * @param float $lifetimeSavings
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setLifetimeSavings($lifetimeSavings);

    /**
     * @return string|null
     */
    public function getCreatedAt();

    /**
     * @param string $createdAt
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setCreatedAt($createdAt);

    /**
     * @return string|null
     */
    public function getUpdatedAt();

    /**
     * @param string $updatedAt
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     */
    public function setUpdatedAt($updatedAt);
}
