<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model;

use Ahy\DiscountAlert\Api\ConfigInterface;
use Ahy\DiscountAlert\Api\Data\RunInterface;
use Ahy\DiscountAlert\Api\Data\RunItemInterface;
use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Ahy\DiscountAlert\Api\RunRepositoryInterface;
use Ahy\DiscountAlert\Model\ResourceModel\Run as RunResource;
use Ahy\DiscountAlert\Model\ResourceModel\RunItem as RunItemResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Math\Random;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;

class RunRepository implements RunRepositoryInterface
{
    private const TOKEN_LENGTH = 32;
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly RunFactory $runFactory,
        private readonly RunResource $runResource,
        private readonly RunItemResource $runItemResource,
        private readonly ConfigInterface $config,
        private readonly Random $random,
        private readonly DateTime $dateTime
    ) {}

    public function create(array $products, float $threshold, int $matchedTotal): RunInterface
    {
        // Captured before the insert, so it points at the previous run.
        $previousRunId  = $this->runResource->getLatestRunId();
        $previousIds    = $previousRunId === null
            ? []
            : \array_flip($this->runItemResource->getProductIds($previousRunId));

        $rows     = $this->buildItemRows(0, $products, $previousIds);
        $newCount = \count(\array_filter(
            $rows,
            static fn (array $row): bool => (bool) $row[RunItemInterface::IS_NEW]
        ));

        // Products the previous run flagged that are no longer above the threshold. Counted
        // so a run knows whether anything changed at all, in either direction.
        $currentIds   = \array_flip(\array_map(
            static fn (array $row): int => (int) $row[RunItemInterface::PRODUCT_ID],
            $rows
        ));
        $removedCount = \count(\array_diff_key($previousIds, $currentIds));

        /** @var Run $run */
        $run = $this->runFactory->create();
        $run->setToken($this->random->getRandomString(self::TOKEN_LENGTH))
            ->setThreshold($threshold)
            ->setProductCount($matchedTotal)
            ->setItemCount(\count($products))
            ->setNewCount($newCount)
            ->setRemovedCount($removedCount);

        try {
            $this->runResource->save($run);

            $runId = (int) $run->getRunId();

            foreach ($rows as &$row) {
                $row[RunItemInterface::RUN_ID] = $runId;
            }
            unset($row);

            $this->runItemResource->insertItems($rows);
        } catch (\Throwable $e) {
            throw new CouldNotSaveException(
                __('Could not record the discount alert run: %1', $e->getMessage()),
                $e instanceof \Exception ? $e : null
            );
        }

        return $run;
    }

    public function getById(int $runId): RunInterface
    {
        /** @var Run $run */
        $run = $this->runFactory->create();
        $this->runResource->load($run, $runId);

        if (!$run->getRunId()) {
            throw new NoSuchEntityException(__('No discount alert run exists with ID %1.', $runId));
        }

        return $run;
    }

    public function getByToken(int $runId, string $token): RunInterface
    {
        $invalid = new Phrase('That discount alert link is invalid.');

        if ($runId <= 0 || $token === '') {
            throw new LocalizedException($invalid);
        }

        try {
            $run = $this->getById($runId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException($invalid, $e);
        }

        if (!\hash_equals($run->getToken(), $token)) {
            throw new LocalizedException($invalid);
        }

        if ($this->hasExpired($run)) {
            throw new LocalizedException(
                __(
                    'That discount alert link expired after %1 day(s). Open Catalog > Discount Alerts to review this run.',
                    $this->config->getLinkLifetimeDays()
                )
            );
        }

        return $run;
    }

    public function getProductIds(int $runId): array
    {
        return $this->runItemResource->getProductIds($runId);
    }

    public function getNewProductIds(int $runId): array
    {
        return $this->runItemResource->getProductIds($runId, true);
    }

    public function markEmailSent(int $runId): void
    {
        $this->runResource->markEmailSent($runId);
    }

    public function getProductLabels(int $runId, array $productIds): array
    {
        return $this->runItemResource->getProductLabels($runId, $productIds);
    }

    public function markItemsDisabled(int $runId, array $productIds, ?int $adminUserId): int
    {
        return $this->runItemResource->markDisabled(
            $runId,
            $productIds,
            $adminUserId,
            $this->dateTime->gmtDate()
        );
    }

    public function markItemsReverted(int $runId, array $productIds, ?int $adminUserId): int
    {
        return $this->runItemResource->markReverted(
            $runId,
            $productIds,
            $adminUserId,
            $this->dateTime->gmtDate()
        );
    }

    public function clearItemsReverted(int $runId, array $productIds): int
    {
        return $this->runItemResource->clearReverted($runId, $productIds);
    }

    public function getSnapshotSpecialPrices(int $runId, array $productIds): array
    {
        return $this->runItemResource->getSnapshotSpecialPrices($runId, $productIds);
    }

    /**
     * Links stop working once the configured lifetime has passed. Zero disables expiry.
     */
    private function hasExpired(RunInterface $run): bool
    {
        $lifetimeDays = $this->config->getLinkLifetimeDays();
        $createdAt    = $run->getCreatedAt();

        if ($lifetimeDays === 0 || $createdAt === null) {
            return false;
        }

        $createdTimestamp = \strtotime($createdAt . ' UTC');

        if ($createdTimestamp === false) {
            return false;
        }

        return $this->dateTime->gmtTimestamp() > $createdTimestamp + $lifetimeDays * self::SECONDS_PER_DAY;
    }

    /**
     * @param  array<int, array<string, mixed>> $products
     * @param  array<int, mixed>                $previousIds Product IDs from the previous run, as keys.
     * @return array<int, array<string, mixed>>
     */
    private function buildItemRows(int $runId, array $products, array $previousIds = []): array
    {
        $rows = [];

        foreach ($products as $product) {
            $name      = $product[Collector::KEY_NAME] ?? null;
            $productId = (int) $product[Collector::KEY_PRODUCT_ID];

            $rows[] = [
                RunItemInterface::RUN_ID         => $runId,
                RunItemInterface::PRODUCT_ID     => $productId,
                RunItemInterface::SKU            => (string) $product[Collector::KEY_SKU],
                RunItemInterface::NAME           => $name === null ? null : (string) $name,
                RunItemInterface::PRICE          => (float) $product[Collector::KEY_PRICE],
                RunItemInterface::SPECIAL_PRICE  => (float) $product[Collector::KEY_SPECIAL_PRICE],
                RunItemInterface::DISCOUNT_PCT   => (float) $product[Collector::KEY_DISCOUNT_PCT],
                RunItemInterface::STATUS_AT_SEND => isset($product[Collector::KEY_STATUS])
                    ? (int) $product[Collector::KEY_STATUS]
                    : null,
                RunItemInterface::IS_NEW         => !isset($previousIds[$productId]) ? 1 : 0,
            ];
        }

        return $rows;
    }
}
