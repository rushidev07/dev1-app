<?php
declare(strict_types=1);

namespace FalcoSense\Search\Api\Data;

interface WebhookMessageInterface
{
    public function getProductId(): int;
    public function getStoreId(): int;
}
