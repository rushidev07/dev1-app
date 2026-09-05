<?php

namespace Ahy\EstateApiIntegration\Service;

use Ahy\EstateApiIntegration\Logger\Logger;

/**
 * Loads Orchid Estate mock responses from a bundled JSON file and looks them up
 * by UPC + ZIP code, standing in for the live Orchid Estate API call.
 *
 * Each JSON entry is { "zip_code": ..., "upc": ..., "response": <int|object> },
 * where "response" already matches the shape EstateApiService expects for a
 * single UPC/ZIP lookup (numeric code, or a restriction/shipping/age object).
 */
class EstateMockDataProvider
{
    private const DATA_FILE = __DIR__ . '/../Data/orchid_estate_custom_responses.json';

    /**
     * @var array<string, array<string, int|array>>|null Indexed by upc => zip => response
     */
    private ?array $index = null;

    /**
     * @var Logger
     */
    private Logger $logger;

    /**
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Look up the mock response for a given UPC/ZIP combination.
     *
     * @param string $upc
     * @param string $zip
     * @return int|array|null Raw response value, or null when no entry matches.
     */
    public function getResponse(string $upc, string $zip)
    {
        $this->loadIndex();

        return $this->index[trim($upc)][trim($zip)] ?? null;
    }

    /**
     * Build the upc => zip => response lookup table from the JSON file, once per request.
     */
    private function loadIndex(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];

        if (!is_readable(self::DATA_FILE)) {
            $this->logger->error('[Orchid Estate Mock] Data file not found: ' . self::DATA_FILE);
            return;
        }

        $entries = json_decode((string) file_get_contents(self::DATA_FILE), true);

        if (!is_array($entries)) {
            $this->logger->error('[Orchid Estate Mock] Failed to decode data file: ' . json_last_error_msg());
            return;
        }

        foreach ($entries as $entry) {
            if (!isset($entry['upc'], $entry['zip_code']) || !array_key_exists('response', $entry)) {
                continue;
            }

            $this->index[trim((string) $entry['upc'])][trim((string) $entry['zip_code'])] = $entry['response'];
        }
    }
}
