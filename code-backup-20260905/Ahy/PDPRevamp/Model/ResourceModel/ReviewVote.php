<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * CRUD for ahy_pdprevamp_review_vote: which customer voted on which review, and
 * in which direction.
 *
 * Exists because Yotpo cannot deduplicate votes for us - the vote request it
 * accepts carries no customer identifier, and it answers 200 OK to everything -
 * so "has this customer already voted" has to be answered locally. Yotpo still
 * owns the vote counts; this table only records who voted.
 *
 * Plain ResourceConnection rather than a full AbstractDb model + collection, the
 * same shape as this module's VariantColor resource: the access patterns are a
 * handful of keyed reads and writes, so the ORM would add indirection without
 * buying anything.
 */
class ReviewVote
{
    /**
     * Only helpful votes exist. There is deliberately no "not helpful"
     * direction, so vote_type is always this value - the column is kept rather
     * than dropped so a second direction could be reintroduced without a schema
     * migration, and so existing rows stay readable.
     */
    public const VOTE_UP = 'up';

    private const TABLE = 'ahy_pdprevamp_review_vote';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * The customer's current vote on a review, or null if they have not voted.
     *
     * Returns the direction rather than a boolean so the storefront can fill the
     * correct icon after a page reload - which is the whole point of persisting
     * this at all.
     */
    public function getVote(string $reviewId, int $customerId): ?string
    {
        if ($reviewId === '' || $customerId < 1) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['vote_type'])
            ->where('review_id = ?', $reviewId)
            ->where('customer_id = ?', $customerId)
            ->limit(1);

        $voteType = $connection->fetchOne($select);

        return $voteType !== false && $voteType !== null ? (string) $voteType : null;
    }

    /**
     * Votes for many reviews at once, as review_id => vote_type.
     *
     * Batched deliberately: the reviews list renders every review on the page, so
     * a per-review lookup would mean one query per review on a product with a
     * long review list.
     *
     * @param string[] $reviewIds
     * @return array<string, string>
     */
    public function getVotesByReviewIds(array $reviewIds, int $customerId): array
    {
        $reviewIds = array_values(array_filter(array_map('strval', $reviewIds), static fn ($id) => $id !== ''));

        if ($reviewIds === [] || $customerId < 1) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['review_id', 'vote_type'])
            ->where('review_id IN (?)', $reviewIds)
            ->where('customer_id = ?', $customerId);

        return array_map('strval', $connection->fetchPairs($select));
    }

    /**
     * How many helpful votes this module has recorded for each review.
     *
     * Needed because Yotpo does not actually persist the votes we send it: the
     * public vote endpoint accepts the request, answers 200 OK, and leaves
     * votes_up unchanged (verified against the live API - a nonexistent review
     * id and even vote/sideways also return 200 OK). Its counts therefore only
     * reflect votes cast through Yotpo's own widget, so ours have to be added on
     * top to be visible at all.
     *
     * Batched for the same reason as getVotesByReviewIds(): one query for the
     * whole page rather than one per review.
     *
     * @param string[] $reviewIds
     * @return array<string, int>
     */
    public function getVoteTallies(array $reviewIds): array
    {
        $reviewIds = array_values(array_filter(array_map('strval', $reviewIds), static fn ($id) => $id !== ''));

        if ($reviewIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                [
                    'review_id',
                    'total' => new \Zend_Db_Expr('COUNT(*)'),
                ]
            )
            ->where('review_id IN (?)', $reviewIds)
            ->where('vote_type = ?', self::VOTE_UP)
            ->group('review_id');

        return array_map('intval', $connection->fetchPairs($select));
    }

    /**
     * Record (or overwrite) a customer's vote.
     *
     * insertOnDuplicate rather than delete-then-insert so switching direction is
     * a single atomic statement that cannot race against itself, and so a
     * genuine duplicate submit is harmless instead of throwing.
     *
     * @throws DuplicateException on a constraint failure the adapter cannot absorb
     */
    public function saveVote(string $reviewId, int $customerId, string $voteType): void
    {
        if ($reviewId === '' || $customerId < 1 || !self::isValidVoteType($voteType)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'review_id' => $reviewId,
                'customer_id' => $customerId,
                'vote_type' => $voteType,
            ],
            ['vote_type']
        );
    }

    public function removeVote(string $reviewId, int $customerId): void
    {
        if ($reviewId === '' || $customerId < 1) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'review_id = ?' => $reviewId,
                'customer_id = ?' => $customerId,
            ]
        );
    }

    public static function isValidVoteType(string $voteType): bool
    {
        return $voteType === self::VOTE_UP;
    }
}
