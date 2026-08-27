<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\ResourceModel;

use Ahy\DiscountAlert\Api\Data\RunInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Run extends AbstractDb
{
    public const TABLE_NAME = 'ahy_discount_alert_run';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, RunInterface::RUN_ID);
    }

    /**
     * Record that this run produced an email.
     *
     * A targeted update rather than a model save, so it cannot disturb anything else on
     * the row after the email has already gone out.
     */
    public function markEmailSent(int $runId): void
    {
        $this->getConnection()->update(
            $this->getMainTable(),
            [RunInterface::EMAIL_SENT => 1],
            [RunInterface::RUN_ID . ' = ?' => $runId]
        );
    }

    /**
     * ID of the most recently recorded run, or null when none exist.
     *
     * Called before a new run is inserted, so it returns the run to compare against when
     * deciding which products are newly flagged.
     */
    public function getLatestRunId(): ?int
    {
        $connection = $this->getConnection();

        $runId = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [RunInterface::RUN_ID])
                ->order(RunInterface::RUN_ID . ' DESC')
                ->limit(1)
        );

        return $runId === false || $runId === null ? null : (int) $runId;
    }
}
