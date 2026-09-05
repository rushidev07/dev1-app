<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model;

use FalcoSense\Search\Api\Data\WebhookMessageInterface;

class WebhookMessage implements WebhookMessageInterface
{
    public function __construct(
        private int $productId = 0,
        private int $storeId   = 0,
    ) {}

    public function getProductId(): int { return $this->productId; }
    public function getStoreId(): int   { return $this->storeId; }
}
