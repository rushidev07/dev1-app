<?php

declare(strict_types=1);

namespace Ahy\EstateApiIntegration\Model;

use Ahy\EstateApiIntegration\Api\OrchidRestrictionManagementInterface;
use Ahy\EstateApiIntegration\Api\RestrictedProductCheckerInterface;
use Ahy\EstateApiIntegration\Service\EstateApiService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Ahy\EstateApiIntegration\Logger\Logger;

/**
 * Class OrchidRestrictionManagement
 * Manages saving individual item restrictions and aggregating the highest priority raw response for the quote.
 */
class OrchidRestrictionManagement implements OrchidRestrictionManagementInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param CartRepositoryInterface $cartRepository
     * @param OrchidRestrictionAggregator $aggregator
     * @param Logger $logger
     * @param EstateApiService $estateApiService
     * @param ProductRepositoryInterface $productRepository
     * @param RestrictedProductCheckerInterface $restrictedChecker
     */
    public function __construct(
        private CheckoutSession $checkoutSession,
        private CartRepositoryInterface $cartRepository,
        private OrchidRestrictionAggregator $aggregator,
        private Logger $logger,
        private EstateApiService $estateApiService,
        private ProductRepositoryInterface $productRepository,
        private RestrictedProductCheckerInterface $restrictedChecker
    ) {}

    /**
     * Saves the restriction level for a specific product and re-evaluates the overall quote restriction.
     *
     * @param mixed $responseCode The raw response (array or string/numeric)
     * @param int $productId
     * @return bool
     */
    public function saveRestriction($responseCode, int $productId): bool
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId()) {
                $this->logger->info('[Orchid] No active quote found in session.');
                return false;
            }

            // Prepare the raw string to save (JSON string or raw value)
            $rawToSave = is_array($responseCode)
                ? json_encode($responseCode)
                : (string) $responseCode;

            /**
             * 1. SAVE ON ITEM LEVEL
             * Every item saves its own raw response directly.
             */
            $itemUpdated = false;
            foreach ($quote->getAllItems() as $item) {
                if ((int) $item->getProductId() === $productId) {
                    $item->setData('orchid_restriction_level', $rawToSave);
                    $itemUpdated = true;
                }
            }

            if (!$itemUpdated) {
                $this->logger->warning(sprintf('[Orchid] Product ID %d not found in quote items.', $productId));
                return false;
            }

            // Save the quote to persist item data before aggregation
            $this->cartRepository->save($quote);

            // Reload to ensure we have fresh data for all items
            $quote = $this->cartRepository->get($quote->getId());

            // 2 + 3. Re-evaluate quote-level restriction and persist the winning raw response.
            $this->aggregateQuote($quote);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[Orchid] Save failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Validates every RESTRICTED cart item against the ZIP in a single Orchid v1 call,
     * saves each item's restriction level (same storage format as saveRestriction()), and
     * re-aggregates the quote. Non-restricted items are skipped (no save) exactly as the
     * legacy per-item flow does, so order-hold / aggregation behaviour is unchanged.
     */
    public function validateAndSaveCart(string $zip): array
    {
        $decisions = [];

        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId()) {
                $this->logger->info('[Orchid][v1] No active quote found in session.');
                return $decisions;
            }

            // 1. Collect restricted items and resolve their UPCs.
            $upcByProduct  = []; // productId => upc
            $productsByUpc = []; // upc       => [productId, ...]

            foreach ($quote->getAllVisibleItems() as $item) {
                $productId = (int) $item->getProductId();
                if (isset($upcByProduct[$productId]) || isset($decisions[$productId])) {
                    continue; // same product already handled
                }

                // Only restricted products are validated (mirrors the /product/restricted gate).
                $restricted = $this->restrictedChecker->isRestricted($productId);
                if (empty($restricted['is_restricted'])) {
                    $decisions[$productId] = 1; // allowed, nothing to save
                    continue;
                }

                $upc = $this->resolveUpc($productId);
                if ($upc === null || $upc === '') {
                    $this->logger->warning("[Orchid][v1] Missing UPC for product ID {$productId}");
                    $decisions[$productId] = 1; // matches legacy: code 4 lets checkout proceed
                    $upcByProduct[$productId] = null;
                    continue;
                }

                $upcByProduct[$productId] = $upc;
                $productsByUpc[$upc][] = $productId;
            }

            if (empty($productsByUpc)) {
                return $decisions; // nothing restricted with a UPC to validate
            }

            // 2. ONE batch API call for all restricted UPCs.
            $resultsByUpc = $this->estateApiService->validateProductUpcsWithZip(
                array_keys($productsByUpc),
                $zip
            );

            // Index quote items by product id (a product may appear on multiple lines).
            $itemsByProduct = [];
            foreach ($quote->getAllItems() as $item) {
                $itemsByProduct[(int) $item->getProductId()][] = $item;
            }

            // 3. Persist each restricted item's restriction level (identical format to saveRestriction).
            foreach ($upcByProduct as $productId => $upc) {
                if ($upc === null) {
                    continue; // missing UPC already decided above
                }

                $result    = $resultsByUpc[$upc] ?? 4;
                $rawToSave = is_array($result) ? json_encode($result) : (string) $result;

                foreach ($itemsByProduct[$productId] ?? [] as $item) {
                    $item->setData('orchid_restriction_level', $rawToSave);
                }

                $decisions[$productId] = $this->decisionForResult($result);
                $this->logger->info(sprintf(
                    '[Orchid][v1] Product %d (UPC %s) => %s',
                    $productId,
                    $upc,
                    $rawToSave
                ));
            }

            // 4. Persist items, reload, and aggregate the quote once.
            $this->cartRepository->save($quote);
            $quote = $this->cartRepository->get($quote->getId());
            $this->aggregateQuote($quote);

            return $decisions;
        } catch (\Throwable $e) {
            $this->logger->error('[Orchid][v1] validateAndSaveCart failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return $decisions;
        }
    }

    /**
     * Re-evaluate the highest-priority restriction across all quote items and persist the
     * winning raw response onto the quote. Extracted verbatim from the original saveRestriction
     * logic so both the single-item and batch paths aggregate identically.
     */
    private function aggregateQuote(\Magento\Quote\Api\Data\CartInterface $quote): void
    {
        $highestPriorityValue = null;
        $winningRawResponse   = null;

        foreach ($quote->getAllItems() as $item) {
            $itemRaw = $item->getData('orchid_restriction_level');
            if ($itemRaw === null || $itemRaw === '') {
                continue;
            }

            // If JSON (ammo object), compare on shipping_restriction; otherwise the raw string.
            $valueForPriority = $itemRaw;
            if ($this->isJsonObject($itemRaw)) {
                $decoded = json_decode($itemRaw, true);
                $valueForPriority = $decoded['shipping_restriction'] ?? $itemRaw;
            }

            $newHighest = $this->aggregator->aggregate($highestPriorityValue, $valueForPriority);

            if ($newHighest !== $highestPriorityValue || $winningRawResponse === null) {
                $highestPriorityValue = $newHighest;
                $winningRawResponse = $itemRaw;
            }
        }

        if ($winningRawResponse !== null) {
            $quote->setData('orchid_restriction_level', $winningRawResponse);
            $this->cartRepository->save($quote);
            $this->logger->info(sprintf('[Orchid] Quote %d updated with winning raw response: %s', $quote->getId(), $winningRawResponse));
        }
    }

    /**
     * Resolve a product's UPC (upc or upc_number attribute), matching ProductZipValidator.
     */
    private function resolveUpc(int $productId): ?string
    {
        try {
            $product = $this->productRepository->getById($productId);
            $upcAttr = $product->getCustomAttribute('upc')
                ?? $product->getCustomAttribute('upc_number');

            return $upcAttr ? (string) $upcAttr->getValue() : null;
        } catch (\Throwable $e) {
            $this->logger->error("[Orchid][v1] Failed to resolve UPC for product {$productId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Map a normalized API result to a checkout-proceed decision that EXACTLY mirrors the
     * legacy frontend checkProductRestriction()/submitZip() return value, so every existing
     * consumer (cart "Continue to Checkout" loop and Hyvä checkout navigation loop) behaves
     * identically to today:
     *   1 = allowed   0 = blocked
     *
     *   - ammo A                  => 0 (restricted in ZIP)        [submitZip returns 0]
     *   - ammo B + shipping FF    => 0 (cannot ship)              [submitZip returns 0]
     *   - ammo B otherwise        => 1 (allowed)                  [submitZip returns 1]
     *   - numeric 2               => 0 (restricted)               [submitZip returns 0]
     *   - numeric 0,1,3,4,5       => 1 (allowed)                  [submitZip returns 1]
     *
     * NOTE: order holds are driven by the SAVED raw level (set above), NOT this decision code,
     * so roster-state (3) / not-found (5) still hold the order downstream exactly as before.
     *
     * @param int|array $result
     */
    private function decisionForResult($result): int
    {
        if (is_array($result)) {
            $type     = $result['restriction'] ?? null;
            $shipping = $result['shipping_restriction'] ?? null;

            if ($type === 'A') {
                return 0;
            }
            if ($type === 'B') {
                return $shipping === 'FF' ? 0 : 1;
            }
            return 1;
        }

        $code = (int) $result;
        return $code === 2 ? 0 : 1;
    }

    /**
     * Stricter JSON object detection
     */
    private function isJsonObject(string $value): bool
    {
        $trimmed = trim($value);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            json_decode($trimmed);
            return json_last_error() === JSON_ERROR_NONE;
        }
        return false;
    }
}