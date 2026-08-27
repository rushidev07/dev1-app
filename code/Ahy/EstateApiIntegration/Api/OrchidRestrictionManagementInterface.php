<?php
declare(strict_types=1);

namespace Ahy\EstateApiIntegration\Api;

interface OrchidRestrictionManagementInterface
{
    /**
     * Save or update the Orchid restriction level on the quote.
     *
     * @param mixed $responseCode
     * @param int    $productId   Product ID for which the response applies
     *
     * @return bool Returns true on success, false on failure
     */
    public function saveRestriction($responseCode, int $productId): bool;

    /**
     * Validate ALL restricted items in the current cart against a ZIP code in a single
     * Orchid v1 API call, persist each item's restriction level, and re-aggregate the quote.
     *
     * Returns a per-product decision map for the frontend:
     *   productId => 1  (allowed) | 2 (restricted) | 3 (roster state) | 5 (invalid zip) | 0 (blocked)
     *
     * @param string $zip
     * @return array Map of productId => decision code
     */
    public function validateAndSaveCart(string $zip): array;
}
