<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * CRUD for ahy_pdprevamp_exit_coupon: one row per exit-popup coupon claim.
 *
 * Plain ResourceConnection rather than a full AbstractDb model, matching this
 * module's VariantColor and ReviewVote resources - the access patterns are a
 * handful of keyed reads and writes.
 */
class ExitCoupon
{
    private const TABLE = 'ahy_pdprevamp_exit_coupon';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * The claim already held by this email address, or null.
     *
     * A repeat submit returns the existing code rather than issuing a new one -
     * without that, uses_per_coupon=1 is trivially bypassed by submitting the
     * same address repeatedly.
     *
     * times_used is joined from salesrule_coupon rather than read from our own
     * is_redeemed column. is_redeemed is set by Observer\MarkExitCouponRedeemed
     * and so is only correct for claims made since that observer existed;
     * times_used is Magento's own counter and is authoritative. The caller needs
     * the authoritative answer, because it decides whether to re-send a code or
     * tell the customer it is already spent.
     *
     * Null when the coupon row is gone (deleted from the admin's Cart Price Rule
     * screen), which the caller must treat as unusable rather than as zero uses.
     *
     * @return array{coupon_code: string, expires_at: ?string, customer_id: ?int, product_id: ?int, times_used: ?int}|null
     */
    public function getClaimByEmail(string $email): ?array
    {
        $email = $this->normaliseEmail($email);
        if ($email === '') {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['claim' => $this->resourceConnection->getTableName(self::TABLE)],
                ['coupon_code', 'expires_at', 'customer_id', 'product_id']
            )
            ->joinLeft(
                ['coupon' => $this->resourceConnection->getTableName('salesrule_coupon')],
                'coupon.code = claim.coupon_code',
                ['times_used' => 'coupon.times_used']
            )
            ->where('claim.email = ?', $email)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row ?: null;
    }

    /**
     * Whether this customer account already holds a claim.
     *
     * Complements the sales rule's own uses_per_customer limit: this one stops a
     * second code being *issued*, the rule stops a second being *redeemed*.
     */
    public function customerHasClaim(int $customerId): bool
    {
        if ($customerId < 1) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['entity_id'])
            ->where('customer_id = ?', $customerId)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }

    /**
     * Record a new claim.
     *
     * @throws \Magento\Framework\DB\Adapter\DuplicateException when the email
     *         already holds one - callers should read the existing claim first.
     */
    public function saveClaim(
        string $email,
        string $couponCode,
        ?int $customerId,
        ?int $productId,
        ?string $expiresAt
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'email' => $this->normaliseEmail($email),
                'coupon_code' => $couponCode,
                'customer_id' => $customerId > 0 ? $customerId : null,
                'product_id' => $productId > 0 ? $productId : null,
                'expires_at' => $expiresAt,
            ]
        );
    }

    /**
     * Attach a customer id to a claim made while logged out.
     *
     * Called when a guest who claimed a code later registers or signs in, so the
     * one-per-customer check recognises them instead of letting them claim a
     * second code under the same address.
     */
    public function linkCustomerByEmail(string $email, int $customerId): void
    {
        $email = $this->normaliseEmail($email);
        if ($email === '' || $customerId < 1) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['customer_id' => $customerId],
            ['email = ?' => $email, 'customer_id IS NULL']
        );
    }

    /**
     * Flag a claim as redeemed, so the admin grid can report claimed vs used.
     */
    public function markRedeemed(string $couponCode): void
    {
        if ($couponCode === '') {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['is_redeemed' => 1],
            ['coupon_code = ?' => $couponCode]
        );
    }

    /**
     * Lower-cased and trimmed, so "A@B.com" and "a@b.com" cannot each hold a
     * claim - the unique index is byte-wise and would otherwise let both through.
     */
    private function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
