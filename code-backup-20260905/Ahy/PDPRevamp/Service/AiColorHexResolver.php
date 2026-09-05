<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Logger\Logger;
use Ahy\PDPRevamp\Model\CssColorMap;
use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory as SwatchCollectionFactory;
use Magento\Swatches\Model\ResourceModel\Swatch as SwatchResource;
use Magento\Swatches\Model\Swatch;
use Magento\Swatches\Model\SwatchFactory;

/**
 * Fills in Magento's own native visual swatch (eav_attribute_option_swatch)
 * for `color` attribute options an admin never set a color for - the exact
 * "Swatch" color box in Stores > Attributes > Product > color's "Manage
 * Swatch" grid. Standard CSS-named options (e.g. "Red") are resolved
 * directly from CssColorMap at zero cost; anything else (creative/marketing
 * names like "Candy Apple Craw") goes through GeminiClient::resolveColorHex()
 * once, ever, per option - never called live from a storefront request.
 * Consumed by Console\Command\GenerateAiColorHex and Cron\GenerateAiColorHex.
 */
class AiColorHexResolver
{
    private const COLOR_ATTRIBUTE_CODE = 'color';
    private const STATUS_ATTRIBUTE_CODE = 'status';

    /** Stay comfortably under Gemini's free-tier per-minute rate limit, matching GenerateAiDescriptors. */
    private const DELAY_BETWEEN_REQUESTS_MICROSECONDS = 400000;

    /**
     * Labels per Gemini request when resolving via AI. Network round-trips,
     * not tokens, dominate wall-clock time for a large backfill, so batching
     * cuts total AI wait time roughly by this factor versus one request per
     * label - keep it well under Gemini's response-size/token limits.
     */
    private const AI_BATCH_SIZE = 25;

    private GeminiClient $geminiClient;
    private EavConfig $eavConfig;
    private CssColorMap $cssColorMap;
    private NativeColorSwatch $nativeColorSwatch;
    private ProductRepositoryInterface $productRepository;
    private SwatchFactory $swatchFactory;
    private SwatchResource $swatchResource;
    private SwatchCollectionFactory $swatchCollectionFactory;
    private ResourceConnection $resourceConnection;
    private Logger $logger;

    public function __construct(
        GeminiClient $geminiClient,
        EavConfig $eavConfig,
        CssColorMap $cssColorMap,
        NativeColorSwatch $nativeColorSwatch,
        ProductRepositoryInterface $productRepository,
        SwatchFactory $swatchFactory,
        SwatchResource $swatchResource,
        SwatchCollectionFactory $swatchCollectionFactory,
        ResourceConnection $resourceConnection,
        Logger $logger
    ) {
        $this->geminiClient = $geminiClient;
        $this->eavConfig = $eavConfig;
        $this->cssColorMap = $cssColorMap;
        $this->nativeColorSwatch = $nativeColorSwatch;
        $this->productRepository = $productRepository;
        $this->swatchFactory = $swatchFactory;
        $this->swatchResource = $swatchResource;
        $this->swatchCollectionFactory = $swatchCollectionFactory;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * Every option defined on the `color` EAV attribute, option_id => label.
     */
    public function getAllColorOptions(): array
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::COLOR_ATTRIBUTE_CODE);
        if (!$attribute || !$attribute->usesSource()) {
            return [];
        }

        $options = [];
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            $optionId = (int) ($option['value'] ?? 0);
            $label = trim((string) ($option['label'] ?? ''));
            if ($optionId > 0 && $label !== '') {
                $options[$optionId] = $label;
            }
        }

        return $options;
    }

    /**
     * Every `color` option used by at least one enabled product - checked at
     * the product's own status (a configurable's simple children must
     * themselves be enabled, not just their parent), option_id => label.
     * Resolved with one direct SQL join against catalog_product_entity_int
     * rather than loading product collections, so it stays fast on a large
     * (e.g. 100k+ product) catalog.
     */
    public function getEnabledColorOptions(): array
    {
        $colorAttribute = $this->eavConfig->getAttribute(Product::ENTITY, self::COLOR_ATTRIBUTE_CODE);
        $statusAttribute = $this->eavConfig->getAttribute(Product::ENTITY, self::STATUS_ATTRIBUTE_CODE);
        if (!$colorAttribute || !$colorAttribute->getId() || !$statusAttribute || !$statusAttribute->getId()) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity_int');

        $select = $connection->select()
            ->distinct()
            ->from(['c' => $table], ['option_id' => 'c.value'])
            ->joinInner(
                ['s' => $table],
                's.entity_id = c.entity_id'
                    . ' AND s.attribute_id = ' . (int) $statusAttribute->getId()
                    . ' AND s.store_id = 0'
                    . ' AND s.value = ' . (int) Status::STATUS_ENABLED,
                []
            )
            ->where('c.attribute_id = ?', (int) $colorAttribute->getId())
            ->where('c.store_id = ?', 0)
            ->where('c.value IS NOT NULL');

        $optionIds = array_map('intval', $connection->fetchCol($select));
        if (!$optionIds) {
            return [];
        }

        return array_intersect_key($this->getAllColorOptions(), array_flip($optionIds));
    }

    /**
     * Subset of getEnabledColorOptions() that don't have a native visual swatch set yet.
     */
    public function getMissingEnabledColorOptions(): array
    {
        $enabled = $this->getEnabledColorOptions();
        if (!$enabled) {
            return [];
        }

        $withSwatch = $this->nativeColorSwatch->getOptionIdsWithSwatch(array_keys($enabled));

        return array_diff_key($enabled, array_flip($withSwatch));
    }

    /**
     * Subset of getAllColorOptions() that don't have a native visual swatch set yet.
     */
    public function getMissingColorOptions(): array
    {
        $all = $this->getAllColorOptions();
        if (!$all) {
            return [];
        }

        $withSwatch = $this->nativeColorSwatch->getOptionIdsWithSwatch(array_keys($all));

        return array_diff_key($all, array_flip($withSwatch));
    }

    /**
     * Distinct `color` options used by one product - its children's values if
     * it's configurable, or its own value if it's a simple product with one.
     *
     * @return array<int, string> option_id => label
     */
    public function getColorOptionsForSku(string $sku): array
    {
        try {
            $product = $this->productRepository->get($sku, false, null, true);
        } catch (NoSuchEntityException $e) {
            $this->logger->error('[AiColorHexResolver] No such SKU: ' . $sku);
            return [];
        }

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, self::COLOR_ATTRIBUTE_CODE);
        if (!$attribute || !$attribute->usesSource()) {
            return [];
        }
        $source = $attribute->getSource();

        $optionIds = [];
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            foreach ($product->getTypeInstance()->getUsedProducts($product) as $child) {
                $colorValue = $child->getData(self::COLOR_ATTRIBUTE_CODE);
                if ($colorValue !== null && $colorValue !== '') {
                    $optionIds[(int) $colorValue] = true;
                }
            }
        } else {
            $colorValue = $product->getData(self::COLOR_ATTRIBUTE_CODE);
            if ($colorValue !== null && $colorValue !== '') {
                $optionIds[(int) $colorValue] = true;
            }
        }

        $options = [];
        foreach (array_keys($optionIds) as $optionId) {
            $label = $source->getOptionText($optionId);
            if (is_string($label) && $label !== '') {
                $options[$optionId] = $label;
            }
        }

        return $options;
    }

    /**
     * Resolves and saves the native visual swatch for a batch of options.
     * A label naming two colors (e.g. "Black & Blue", "Green Pumpkin &
     * Black") resolves each half independently and stores both hexes
     * together (comma-separated) so the frontend can render a split-color
     * circle instead of a solid one - see ahyBuildSwatchBackground() in
     * color-swatch-resolver.phtml. Standard CSS names resolve for free via
     * CssColorMap; anything else goes through Gemini. Never throws - a
     * single option's failure is recorded in the result and the batch
     * continues.
     *
     * @param array<int, string> $optionIdToLabel
     * @return array<int, array{option_id: int, label: string, hex: ?string, status: string}>
     */
    public function backfill(array $optionIdToLabel, bool $overwrite = false, ?int $limit = null, bool $dryRun = false): array
    {
        $results = [];

        if (!$overwrite) {
            $alreadySet = $this->nativeColorSwatch->getOptionIdsWithSwatch(array_keys($optionIdToLabel));
        } else {
            $alreadySet = [];
        }

        $toProcess = [];
        foreach ($optionIdToLabel as $optionId => $label) {
            if (!$overwrite && in_array($optionId, $alreadySet, true)) {
                $results[$optionId] = ['option_id' => $optionId, 'label' => $label, 'hex' => null, 'status' => 'skipped_already_set'];
                continue;
            }
            $toProcess[$optionId] = $label;
        }

        if ($toProcess) {
            $hexByLabel = $this->resolveLabelsToHex($toProcess, $limit);
            $toSave = [];

            foreach ($toProcess as $optionId => $label) {
                $hex = $this->assembleHexForLabel($label, $hexByLabel);

                if ($hex === null) {
                    $results[$optionId] = ['option_id' => $optionId, 'label' => $label, 'hex' => null, 'status' => 'failed'];
                } elseif ($dryRun) {
                    $results[$optionId] = ['option_id' => $optionId, 'label' => $label, 'hex' => $hex, 'status' => 'dry_run'];
                } else {
                    $toSave[$optionId] = $hex;
                    $results[$optionId] = ['option_id' => $optionId, 'label' => $label, 'hex' => $hex, 'status' => 'resolved'];
                }
            }

            if ($toSave) {
                $this->saveSwatchesBulk($toSave);
            }
        }

        $ordered = [];
        foreach ($optionIdToLabel as $optionId => $label) {
            if (isset($results[$optionId])) {
                $ordered[] = $results[$optionId];
            }
        }

        return $ordered;
    }

    /**
     * Resolves every distinct label (or dual-color half) needed by the given
     * options to a hex, batching whatever the free CssColorMap can't handle
     * into large Gemini requests instead of one request per label - shared
     * labels (or halves shared across options) are only ever sent once.
     * $limit caps how many distinct labels get sent to AI in this run.
     *
     * @param array<int, string> $optionIdToLabel
     * @return array<string, string> label/part => hex
     */
    private function resolveLabelsToHex(array $optionIdToLabel, ?int $limit): array
    {
        $hexByLabel = [];
        $aiQueue = [];

        foreach ($optionIdToLabel as $label) {
            foreach ($this->labelPartsFor($label) as $part) {
                $this->queueForResolution($part, $hexByLabel, $aiQueue, $limit);
            }
        }

        $this->runAiQueue($aiQueue, $hexByLabel);

        // Second pass: a dual-color label whose halves didn't both resolve
        // falls back to trying the whole label as a single color - queue
        // those (rare) fallbacks as one more small batch round.
        $fallbackQueue = [];
        foreach ($optionIdToLabel as $label) {
            $parts = $this->labelPartsFor($label);
            if (count($parts) === 2 && !isset($hexByLabel[$parts[0]], $hexByLabel[$parts[1]])) {
                $this->queueForResolution($label, $hexByLabel, $fallbackQueue, $limit);
            }
        }
        $this->runAiQueue($fallbackQueue, $hexByLabel);

        return $hexByLabel;
    }

    /**
     * @param array<string, true> $hexByLabel
     * @param array<string, true> $aiQueue
     */
    private function queueForResolution(string $label, array &$hexByLabel, array &$aiQueue, ?int $limit): void
    {
        if (isset($hexByLabel[$label]) || isset($aiQueue[$label])) {
            return;
        }

        $cssHex = $this->cssColorMap->getHex(CssColorMap::normalize($label));
        if ($cssHex !== null) {
            $hexByLabel[$label] = $cssHex;
            return;
        }

        if ($limit !== null && count($aiQueue) >= $limit) {
            return; // Over budget for this run - left unresolved, reported as failed.
        }

        $aiQueue[$label] = true;
    }

    /**
     * @param array<string, true> $queue
     * @param array<string, string> $hexByLabel
     */
    private function runAiQueue(array $queue, array &$hexByLabel): void
    {
        if (!$queue) {
            return;
        }

        foreach (array_chunk(array_keys($queue), self::AI_BATCH_SIZE) as $batch) {
            try {
                $resolved = $this->geminiClient->resolveColorHexBatch($batch);
            } catch (\Throwable $exception) {
                $this->logger->error('[AiColorHexResolver] resolveColorHexBatch threw: ' . $exception->getMessage());
                $resolved = [];
            }

            foreach ($resolved as $label => $hex) {
                $hexByLabel[$label] = $hex;
            }

            usleep(self::DELAY_BETWEEN_REQUESTS_MICROSECONDS);
        }
    }

    /**
     * @param array<string, string> $hexByLabel
     */
    private function assembleHexForLabel(string $label, array $hexByLabel): ?string
    {
        $parts = $this->labelPartsFor($label);

        if (count($parts) === 2) {
            if (isset($hexByLabel[$parts[0]], $hexByLabel[$parts[1]])) {
                return $hexByLabel[$parts[0]] . ',' . $hexByLabel[$parts[1]];
            }
            // Halves didn't both resolve - fall back to the whole label (queued above).
            return $hexByLabel[$label] ?? null;
        }

        return $hexByLabel[$label] ?? null;
    }

    /**
     * @return string[] one label (single color) or two (dual-color split)
     */
    private function labelPartsFor(string $label): array
    {
        return $this->splitDualColorLabel($label) ?? [$label];
    }

    /**
     * Splits a label like "Black & Blue" or "Green Pumpkin & Black" into its
     * two named colors, or returns null when the label doesn't name two
     * colors this way (e.g. "Chartreuse Black Back", "Camo Craw").
     *
     * @return array{0: string, 1: string}|null
     */
    private function splitDualColorLabel(string $label): ?array
    {
        if (preg_match('/^(.+?)\s*(?:&|\/|\+|\band\b)\s*(.+)$/i', trim($label), $matches)) {
            $first = trim($matches[1]);
            $second = trim($matches[2]);
            if ($first !== '' && $second !== '') {
                return [$first, $second];
            }
        }

        return null;
    }

    /**
     * Bulk-imports a color name -> hex mapping (e.g. parsed from a CSV of a
     * brand's official color chart) directly, skipping AI/CSS resolution
     * entirely since the hex is already known. Each name is matched against
     * the `color` attribute's existing options (case/whitespace-insensitive
     * via CssColorMap::normalize()); a name with no matching option is
     * reported back as unmatched rather than silently dropped. Consumed by
     * Console\Command\ImportColorHexMapping.
     *
     * @param array<string, string> $nameToHex color option label => hex code
     * @return array{matched: int, saved: int, would_save: int, skipped_already_set: int, unmatched: string[], invalid_hex: string[]}
     */
    public function importMapping(array $nameToHex, bool $overwrite = false, bool $dryRun = false): array
    {
        $labelToOptionId = [];
        foreach ($this->getAllColorOptions() as $optionId => $label) {
            $labelToOptionId[CssColorMap::normalize($label)] = $optionId;
        }

        $unmatched = [];
        $invalidHex = [];
        $optionIdToHex = [];

        foreach ($nameToHex as $name => $hex) {
            $hex = strtoupper(trim($hex));
            if (!$this->isValidHex($hex)) {
                $invalidHex[] = $name;
                continue;
            }

            $normalized = CssColorMap::normalize($name);
            if (!isset($labelToOptionId[$normalized])) {
                $unmatched[] = $name;
                continue;
            }

            $optionIdToHex[$labelToOptionId[$normalized]] = $hex;
        }

        $skippedAlreadySet = 0;
        if (!$overwrite && $optionIdToHex) {
            $alreadySet = $this->nativeColorSwatch->getOptionIdsWithSwatch(array_keys($optionIdToHex));
            foreach ($alreadySet as $optionId) {
                if (isset($optionIdToHex[$optionId])) {
                    unset($optionIdToHex[$optionId]);
                    $skippedAlreadySet++;
                }
            }
        }

        if ($optionIdToHex && !$dryRun) {
            $this->saveSwatchesBulk($optionIdToHex);
        }

        return [
            'matched' => count($optionIdToHex) + $skippedAlreadySet,
            'saved' => $dryRun ? 0 : count($optionIdToHex),
            'would_save' => $dryRun ? count($optionIdToHex) : 0,
            'skipped_already_set' => $skippedAlreadySet,
            'unmatched' => $unmatched,
            'invalid_hex' => $invalidHex,
        ];
    }

    /**
     * Saves an admin-entered pair of colors for one option from the
     * "Two-Color Swatches" admin panel, in the same comma-paired convention
     * getMissingColorOptions()/backfill() write for AI-resolved pairs.
     * Silently no-ops on an invalid hex - see
     * Plugin\Catalog\Controller\Adminhtml\Product\Attribute\Save\SaveTwoColorSwatch,
     * which calls this once per submitted row without pre-validating.
     */
    public function saveManualTwoColorHex(int $optionId, string $first, string $second): void
    {
        if ($optionId < 1 || !$this->isValidHex($first) || !$this->isValidHex($second)) {
            return;
        }

        $this->saveSwatch($optionId, strtoupper($first) . ',' . strtoupper($second));
    }

    private function isValidHex(string $value): bool
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', trim($value)) === 1;
    }

    /**
     * Updates the option's existing default-scope swatch row if one exists
     * (converting it to a visual-color swatch if it was some other type -
     * e.g. a blank textual placeholder row auto-created when the option was
     * added in the admin "Manage Swatch" grid), otherwise inserts a new one.
     *
     * Must match on (option_id, store_id) only, NOT type: the real DB unique
     * key on eav_attribute_option_swatch is (store_id, option_id) alone, so
     * filtering by type here would miss an existing non-color row and then
     * collide with that constraint on insert.
     */
    private function saveSwatch(int $optionId, string $hex): void
    {
        $collection = $this->swatchCollectionFactory->create();
        $collection->addFieldToFilter('option_id', $optionId);
        $collection->addFieldToFilter('store_id', 0);
        $existing = $collection->getFirstItem();

        if ($existing->getId()) {
            $existing->setType(Swatch::SWATCH_TYPE_VISUAL_COLOR);
            $existing->setValue($hex);
            $this->swatchResource->save($existing);
            return;
        }

        $swatch = $this->swatchFactory->create();
        $swatch->setData([
            'option_id' => $optionId,
            'store_id' => 0,
            'type' => Swatch::SWATCH_TYPE_VISUAL_COLOR,
            'value' => $hex,
        ]);
        $this->swatchResource->save($swatch);
    }

    /**
     * Bulk equivalent of saveSwatch() used by backfill(): one upsert query
     * per chunk instead of a SELECT + INSERT/UPDATE per option, which is the
     * dominant cost once AI resolution itself is batched. Relies on the same
     * (store_id, option_id) unique key saveSwatch() documents - insertOnDuplicate
     * updates that row's type/value if it already exists (any type), or inserts
     * a fresh visual-color row otherwise.
     *
     * @param array<int, string> $optionIdToHex
     */
    private function saveSwatchesBulk(array $optionIdToHex): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('eav_attribute_option_swatch');

        foreach (array_chunk($optionIdToHex, 500, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $optionId => $hex) {
                $rows[] = [
                    'option_id' => $optionId,
                    'store_id' => 0,
                    'type' => Swatch::SWATCH_TYPE_VISUAL_COLOR,
                    'value' => $hex,
                ];
            }
            $connection->insertOnDuplicate($table, $rows, ['type', 'value']);
        }
    }
}
