<?php

namespace Ahy\EstateApiIntegration\Service;

use Ahy\EstateApiIntegration\Logger\Logger;

/**
 * Service class to interact with the Orchid Estate API
 *
 * Provides functionality to validate product UPC codes against a given zip code.
 * Backed by a bundled JSON mock data file (EstateMockDataProvider) instead of the
 * live Orchid Estate API, while preserving the exact same return shapes.
 */
class EstateApiService
{
    /**
     * @var EstateMockDataProvider
     */
    private EstateMockDataProvider $mockDataProvider;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * EstateApiService constructor.
     *
     * @param EstateMockDataProvider $mockDataProvider
     * @param Logger $logger
     */
    public function __construct(
        EstateMockDataProvider $mockDataProvider,
        Logger $logger
    ) {
        $this->mockDataProvider = $mockDataProvider;
        $this->logger = $logger;
    }

    /**
     * Validates the given product UPC code against the provided zip code via Orchid API
     *
     * @param string $upc The UPC code of the product to validate
     * @param string $zip The zip code for validation
     * @return mixed Returns:
     *               - integer for old numeric responses
     *               - wrapped associative array for new format
     *               - 4 for errors
     */
    public function validateProductUpcWithZip(string $upc, string $zip)
    {
        try {
            $this->logger->info('[Orchid Estate Mock] Lookup', ['upc' => $upc, 'zip' => $zip]);

            $rawResponse = $this->mockDataProvider->getResponse($upc, $zip);

            if ($rawResponse === null) {
                $this->logger->warning("No mock response found for UPC {$upc} / ZIP {$zip}");
                return 4;
            }

            /**
             * CASE 1: OLD FORMAT → INTEGER RESPONSE
             */
            if (is_int($rawResponse)) {
                if (in_array($rawResponse, [1, 2, 3, 5, 0], true)) {
                    $this->logger->info("Valid numeric response received: " . $rawResponse);
                    return $rawResponse;
                }

                $this->logger->warning("Unexpected numeric response: " . $rawResponse);
                return 4;
            }

            /**
             * CASE 2: NEW FORMAT → OBJECT
             * We return the full object wrapped under a key to preserve structure
             * during frontend/backend serialization cycles.
             */
            if (is_array($rawResponse)) {
                $this->logger->info("Orchid new object response received", $rawResponse);

                // IMPORTANT: wrap under key so keys remain intact through serialization
                return ['orchid' => $rawResponse];
            }

            /**
             * UNKNOWN RESPONSE FORMAT
             */
            $this->logger->warning("Unknown Orchid response format: " . json_encode($rawResponse));
            return 4;

        } catch (\Exception $e) {
            $this->logger->error("Estate API call failed: " . $e->getMessage());
            return 4;
        }
    }

    /**
     * Validate MULTIPLE UPC codes against a ZIP code in a single call (Orchid v1 endpoint).
     *
     * Sends all UPCs comma-separated to /api/v1/submit_upc and returns a map keyed by the
     * (trimmed) UPC string. Each value is normalized to the SAME shape the legacy single-UPC
     * path stores downstream, so the aggregator / order-hold logic is unaffected:
     *   - int                 → firearm/magazine/part numeric code (1,2,3,5,0)
     *   - associative array   → ammunition object [restriction, shipping_restriction, age_restriction]
     *   - 4                   → error / not found / unmapped UPC
     *
     * @param string[] $upcs
     * @param string   $zip
     * @return array<string,int|array> Map of UPC => normalized result
     */
    public function validateProductUpcsWithZip(array $upcs, string $zip): array
    {
        // Trim + drop empties + dedupe. The v1 response is keyed by the exact UPC string,
        // so trimming here is required for the lookup below to match.
        $cleanUpcs = [];
        foreach ($upcs as $upc) {
            $trimmed = trim((string) $upc);
            if ($trimmed !== '') {
                $cleanUpcs[$trimmed] = $trimmed;
            }
        }
        $cleanUpcs = array_values($cleanUpcs);

        if (empty($cleanUpcs)) {
            return [];
        }

        try {
            $this->logger->info('[Orchid Estate Mock][v1] Lookup', ['upcs' => $cleanUpcs, 'zip' => $zip]);

            $results = [];
            foreach ($cleanUpcs as $upc) {
                $node = $this->mockDataProvider->getResponse($upc, $zip);
                $results[$upc] = $node === null ? 4 : $this->normalizeNode($node);
            }

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('[v1] Estate API call failed: ' . $e->getMessage());
            return $this->fillResults($cleanUpcs, 4);
        }
    }

    /**
     * Normalize a single v1 per-UPC response node into the canonical internal shape.
     *
     * @param mixed $node
     * @return int|array
     */
    private function normalizeNode($node)
    {
        // Ammunition: object carrying shipping / age restrictions.
        if (is_array($node) && (isset($node['shipping_restriction']) || isset($node['age_restriction']))) {
            return [
                'restriction'          => $node['restriction'] ?? null,
                'shipping_restriction' => $node['shipping_restriction'] ?? null,
                'age_restriction'      => $node['age_restriction'] ?? null,
            ];
        }

        // Firearms / magazines / parts: v1 wraps the numeric code as { "restriction": N }.
        if (is_array($node) && isset($node['restriction'])) {
            $code = $node['restriction'];
            if (is_int($code) || (is_string($code) && ctype_digit($code))) {
                $code = (int) $code;
                return in_array($code, [1, 2, 3, 5, 0], true) ? $code : 4;
            }
            // Unexpected non-numeric scalar restriction with no shipping/age context.
            return 4;
        }

        // Bare numeric (defensive; v1 normally wraps in an object).
        if (is_int($node)) {
            return in_array($node, [1, 2, 3, 5, 0], true) ? $node : 4;
        }
        if (is_string($node) && ctype_digit($node)) {
            $code = (int) $node;
            return in_array($code, [1, 2, 3, 5, 0], true) ? $code : 4;
        }

        $this->logger->warning('[v1] Unknown node format: ' . json_encode($node));
        return 4;
    }

    /**
     * Build a map of UPC => $value for every UPC (used for uniform error returns).
     *
     * @param string[]   $upcs
     * @param int|array  $value
     * @return array
     */
    private function fillResults(array $upcs, $value): array
    {
        $out = [];
        foreach ($upcs as $upc) {
            $out[$upc] = $value;
        }
        return $out;
    }
}
