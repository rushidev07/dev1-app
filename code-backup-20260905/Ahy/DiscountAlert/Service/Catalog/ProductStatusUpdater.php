<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Catalog;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Store\Model\Store;

/**
 * Bulk status changes, using the same core action the native product grid mass action
 * uses so indexers and attribute-update events behave identically.
 */
class ProductStatusUpdater
{
    /**
     * Products per update statement. Keeps a large selection from building one huge
     * query and from queueing a single oversized reindex batch.
     */
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ProductAction $productAction
    ) {}

    /**
     * @param int[] $productIds
     */
    public function disable(array $productIds): void
    {
        $this->setStatus($productIds, Status::STATUS_DISABLED);
    }

    /**
     * @param int[] $productIds
     */
    private function setStatus(array $productIds, int $status): void
    {
        $productIds = \array_values(\array_unique(\array_filter($productIds)));

        if (!$productIds) {
            return;
        }

        foreach (\array_chunk($productIds, self::BATCH_SIZE) as $chunk) {
            $this->productAction->updateAttributes(
                $chunk,
                [ProductInterface::STATUS => $status],
                Store::DEFAULT_STORE_ID
            );
        }
    }
}
