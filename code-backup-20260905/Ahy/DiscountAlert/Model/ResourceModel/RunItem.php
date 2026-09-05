<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\ResourceModel;

use Ahy\DiscountAlert\Api\Data\RunItemInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RunItem extends AbstractDb
{
    public const TABLE_NAME = 'ahy_discount_alert_run_item';

    /**
     * Rows per INSERT / UPDATE statement, so a catalog-wide run does not build one
     * oversized query.
     */
    private const BATCH_SIZE = 500;

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, RunItemInterface::ITEM_ID);
    }

    /**
     * Bulk-insert the products recorded against a run.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return int Rows inserted.
     */
    public function insertItems(array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $connection = $this->getConnection();
        $table      = $this->getMainTable();
        $inserted   = 0;

        foreach (\array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $inserted += (int) $connection->insertMultiple($table, $chunk);
        }

        return $inserted;
    }

    /**
     * @param  bool|null $isNew Null for every product, true or false to filter on the flag.
     * @return int[]
     */
    public function getProductIds(int $runId, ?bool $isNew = null): array
    {
        $connection = $this->getConnection();

        $select = $connection->select()
            ->from($this->getMainTable(), [RunItemInterface::PRODUCT_ID])
            ->where(RunItemInterface::RUN_ID . ' = ?', $runId);

        if ($isNew !== null) {
            $select->where(RunItemInterface::IS_NEW . ' = ?', $isNew ? 1 : 0);
        }

        $ids = $connection->fetchCol($select);

        return \array_map('intval', $ids);
    }

    /**
     * SKU and name recorded for the given products, keyed by product ID.
     *
     * Read from the run's own snapshot rather than the catalog, so the message names the
     * product as the alert reported it.
     *
     * @param  int[] $productIds
     * @return array<int, array{sku: string, name: string|null}>
     */
    public function getProductLabels(int $runId, array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->getMainTable(), [
                    RunItemInterface::PRODUCT_ID,
                    RunItemInterface::SKU,
                    RunItemInterface::NAME,
                ])
                ->where(RunItemInterface::RUN_ID . ' = ?', $runId)
                ->where(RunItemInterface::PRODUCT_ID . ' IN (?)', $productIds)
        );

        $labels = [];
        foreach ($rows as $row) {
            $labels[(int) $row[RunItemInterface::PRODUCT_ID]] = [
                'sku'  => (string) $row[RunItemInterface::SKU],
                'name' => $row[RunItemInterface::NAME] === null ? null : (string) $row[RunItemInterface::NAME],
            ];
        }

        return $labels;
    }

    /**
     * Stamp who disabled which products and when.
     *
     * @param  int[] $productIds
     * @return int   Rows affected.
     */
    public function markDisabled(int $runId, array $productIds, ?int $adminUserId, string $disabledAtUtc): int
    {
        if (!$productIds) {
            return 0;
        }

        $connection = $this->getConnection();
        $affected   = 0;

        foreach (\array_chunk($productIds, self::BATCH_SIZE) as $chunk) {
            $affected += (int) $connection->update(
                $this->getMainTable(),
                [
                    RunItemInterface::DISABLED_AT => $disabledAtUtc,
                    RunItemInterface::DISABLED_BY => $adminUserId,
                ],
                [
                    RunItemInterface::RUN_ID . ' = ?'        => $runId,
                    RunItemInterface::PRODUCT_ID . ' IN (?)' => $chunk,
                ]
            );
        }

        return $affected;
    }

    /**
     * Stamp who reverted which products to MSRP and when.
     *
     * @param  int[] $productIds
     * @return int   Rows affected.
     */
    public function markReverted(int $runId, array $productIds, ?int $adminUserId, string $revertedAtUtc): int
    {
        return $this->stampRevert($runId, $productIds, [
            RunItemInterface::REVERTED_AT => $revertedAtUtc,
            RunItemInterface::REVERTED_BY => $adminUserId,
        ]);
    }

    /**
     * Clear the revert stamp, so a restored product reads as never reverted and its
     * Revert action becomes available again.
     *
     * @param  int[] $productIds
     * @return int   Rows affected.
     */
    public function clearReverted(int $runId, array $productIds): int
    {
        return $this->stampRevert($runId, $productIds, [
            RunItemInterface::REVERTED_AT => null,
            RunItemInterface::REVERTED_BY => null,
        ]);
    }

    /**
     * Special price recorded at send time, keyed by product ID.
     *
     * This is the snapshot a restore puts back. Read from the run rather than the catalog
     * because the catalog no longer holds the value once it has been reverted.
     *
     * @param  int[] $productIds
     * @return array<int, float>
     */
    public function getSnapshotSpecialPrices(int $runId, array $productIds): array
    {
        if (!$productIds) {
            return [];
        }

        $connection = $this->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->getMainTable(), [
                    RunItemInterface::PRODUCT_ID,
                    RunItemInterface::SPECIAL_PRICE,
                ])
                ->where(RunItemInterface::RUN_ID . ' = ?', $runId)
                ->where(RunItemInterface::PRODUCT_ID . ' IN (?)', $productIds)
                ->where(RunItemInterface::SPECIAL_PRICE . ' > 0')
        );

        $prices = [];
        foreach ($rows as $row) {
            $prices[(int) $row[RunItemInterface::PRODUCT_ID]] = (float) $row[RunItemInterface::SPECIAL_PRICE];
        }

        return $prices;
    }

    /**
     * @param  int[]                $productIds
     * @param  array<string, mixed> $values
     * @return int                  Rows affected.
     */
    private function stampRevert(int $runId, array $productIds, array $values): int
    {
        if (!$productIds) {
            return 0;
        }

        $connection = $this->getConnection();
        $affected   = 0;

        foreach (\array_chunk($productIds, self::BATCH_SIZE) as $chunk) {
            $affected += (int) $connection->update(
                $this->getMainTable(),
                $values,
                [
                    RunItemInterface::RUN_ID . ' = ?'        => $runId,
                    RunItemInterface::PRODUCT_ID . ' IN (?)' => $chunk,
                ]
            );
        }

        return $affected;
    }
}
