<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Model\Import;

use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;

/**
 * Bulk-sets per-category PLP content from a CSV.
 *
 * Header (order-independent, case-insensitive):
 *   category_id [, category_name] [, store_id] + one or more of FIELDS
 *
 * category_id is the key. category_name is a CHECKSUM, not a lookup: this tree has
 * genuinely duplicated names ("ADP Crew Socks" exists at 3227 and 3235), so matching
 * by name would silently write to the wrong category. When the supplied name does not
 * match the category's real name the row is skipped and reported — that is what
 * catches a stale spreadsheet before it does damage.
 *
 * Column semantics, which is what makes a partial file safe:
 *   - column ABSENT from the header  -> that attribute is never touched
 *   - column present but BLANK       -> the value is cleared
 * So a keywords-only export cannot wipe every description on re-upload, while an
 * admin can still deliberately empty a field.
 *
 * store_id is optional; omitted means 0 (default scope), which every store view
 * inherits. Both attributes are SCOPE_STORE, so a per-view value can be set by
 * supplying the column.
 *
 * Deliberately free of admin/console concerns so both can drive it.
 */
class CardKeywordsImporter
{
    /** CSV column => category attribute. Add a row here to support another field. */
    public const FIELDS = [
        'keywords'             => 'ahy_card_keywords',
        'featured_description' => 'ahy_featured_description',
    ];

    private const COL_ID    = 'category_id';
    private const COL_NAME  = 'category_name';
    private const COL_STORE = 'store_id';

    public function __construct(
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryResource $categoryResource
    ) {}

    /**
     * @return array{
     *     updated: array<string,int>,
     *     unchanged: array<string,int>,
     *     skipped: int,
     *     failed: int,
     *     columns: string[],
     *     messages: string[]
     * }
     */
    public function import(string $filePath, bool $dryRun = false): array
    {
        $result = [
            'updated'   => array_fill_keys(array_keys(self::FIELDS), 0),
            'unchanged' => array_fill_keys(array_keys(self::FIELDS), 0),
            'skipped'   => 0,
            'failed'    => 0,
            'columns'   => [],
            'messages'  => [],
        ];

        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            $result['failed']++;
            $result['messages'][] = 'Could not open the uploaded file.';
            return $result;
        }

        try {
            $header = $this->readHeader($handle);
            if ($header === null) {
                $result['failed']++;
                $result['messages'][] = sprintf(
                    'The file needs a header row with "%s" and at least one of: %s.',
                    self::COL_ID,
                    implode(', ', array_keys(self::FIELDS))
                );
                return $result;
            }

            // Only the content columns actually supplied get written.
            $result['columns'] = array_values(array_filter(
                array_keys(self::FIELDS),
                static fn(string $col) => isset($header[$col])
            ));

            $line = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null] || $row === false || $this->isBlankRow($row)) {
                    continue;
                }
                $this->processRow($row, $header, $result['columns'], $line, $dryRun, $result);
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * @return array<string,int>|null column name => index
     */
    private function readHeader($handle): ?array
    {
        $raw = fgetcsv($handle);
        if ($raw === false || $raw === null) {
            return null;
        }

        $map = [];
        foreach ($raw as $i => $name) {
            // Strip a UTF-8 BOM off the first cell — Excel writes one and it would
            // otherwise make "category_id" never match.
            $name = preg_replace('/^\xEF\xBB\xBF/', '', (string) $name);
            $map[strtolower(trim($name))] = $i;
        }

        if (!isset($map[self::COL_ID])) {
            return null;
        }
        foreach (array_keys(self::FIELDS) as $col) {
            if (isset($map[$col])) {
                return $map;
            }
        }
        return null;
    }

    /**
     * @param string[] $row
     * @param array<string,int> $header
     * @param string[] $columns content columns present in this file
     * @param array<string,mixed> $result
     */
    private function processRow(array $row, array $header, array $columns, int $line, bool $dryRun, array &$result): void
    {
        $categoryId = (int) trim((string) ($row[$header[self::COL_ID]] ?? ''));
        $storeId    = isset($header[self::COL_STORE])
            ? (int) trim((string) ($row[$header[self::COL_STORE]] ?? '0'))
            : 0;

        if ($categoryId <= 0) {
            $result['skipped']++;
            $result['messages'][] = sprintf('Line %d: missing or invalid %s.', $line, self::COL_ID);
            return;
        }

        $category = $this->categoryFactory->create()->setStoreId($storeId)->load($categoryId);
        if (!$category->getId()) {
            $result['skipped']++;
            $result['messages'][] = sprintf('Line %d: category %d does not exist.', $line, $categoryId);
            return;
        }

        // Checksum, not a lookup — see the class docblock.
        if (isset($header[self::COL_NAME])) {
            $expected = trim((string) ($row[$header[self::COL_NAME]] ?? ''));
            $actual   = trim((string) $category->getName());
            if ($expected !== '' && mb_strtolower($expected) !== mb_strtolower($actual)) {
                $result['skipped']++;
                $result['messages'][] = sprintf(
                    'Line %d: category %d is "%s", but the file says "%s" — skipped.',
                    $line,
                    $categoryId,
                    $actual,
                    $expected
                );
                return;
            }
        }

        foreach ($columns as $column) {
            $attribute = self::FIELDS[$column];
            $value     = trim((string) ($row[$header[$column]] ?? ''));

            if (trim((string) $category->getData($attribute)) === $value) {
                $result['unchanged'][$column]++;
                continue;
            }

            if ($dryRun) {
                $result['updated'][$column]++;
                $result['messages'][] = sprintf(
                    'Line %d: would set %s on category %d (%s).',
                    $line,
                    $column,
                    $categoryId,
                    $category->getName()
                );
                continue;
            }

            try {
                $category->setData($attribute, $value);
                // Writes the single EAV row without a full category save, so no
                // reindex storm and no catalog_category_save_after observers fire
                // per row.
                $this->categoryResource->saveAttribute($category, $attribute);
                $result['updated'][$column]++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['messages'][] = sprintf(
                    'Line %d: %s on category %d failed — %s',
                    $line,
                    $column,
                    $categoryId,
                    $e->getMessage()
                );
            }
        }
    }

    /** @param string[] $row */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
