<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Compresses each product's main `image` (never the full gallery — same
 * single-image-per-SKU scope as the reference resize-images.php script) into
 * this module's own media tree, bucketed the same way the reference
 * resize-images.php script buckets Klevu-style image caches:
 *   pub/media/falcosense/800x800/{c1}/{c2}/{filename}
 * The bucket is a hardcoded 800x800 tier, not the source's actual
 * dimensions. The engine resizes to fit within an 800x800 box (preserving
 * aspect ratio, never upscaling, never cropping/stretching) before
 * re-encoding, so originals under catalog/product are never touched, read
 * from for writing, or overwritten by anything outside this module.
 *
 * "Already present" = a compressed copy exists AND is at least as new as the
 * source file's mtime. A product whose image attribute was swapped for a
 * new file re-compresses automatically; one that's untouched since its last
 * successful compression is skipped outright — no re-encode work wasted.
 */
class ProductImageCompressionService
{
    private const SOURCE_SUBDIR = 'catalog/product';
    private const DEST_ROOT     = 'falcosense';
    private const DEST_BUCKET   = '800x800';

    public function __construct(
        private readonly ResourceConnection      $resource,
        private readonly Filesystem              $filesystem,
        private readonly ImageCompressionEngine   $engine,
        private readonly LoggerInterface          $logger,
    ) {
    }

    /**
     * $limit is a hard cap, but the cursor only ever advances once a run has
     * fully drained the backlog (i.e. fewer rows came back than were asked
     * for). Ordering newest-first means a truncated run only sees the top of
     * the backlog — advancing the cursor to that top value would permanently
     * strand any older, still-unprocessed rows below the cutoff. Callers
     * MUST check `truncated` and skip persisting the cursor when true; the
     * next run then re-queries the same (unmoved) cursor and simply re-skips
     * anything already compressed via the mtime check, at near-zero cost.
     *
     * @return array{compressed:int, skipped:int, missing:int, failed:int, checked:int, truncated:bool, newestUpdatedAt:?string}
     */
    public function run(?string $sinceUpdatedAt, int $limit): array
    {
        $rows      = $this->fetchCandidateProducts($sinceUpdatedAt, $limit + 1);
        $truncated = count($rows) > $limit;
        $rows      = array_slice($rows, 0, $limit);

        $stats = [
            'compressed' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0, 'checked' => 0,
            'truncated' => $truncated,
            'newestUpdatedAt' => $rows[0]['updated_at'] ?? null, // rows are newest-first
        ];

        $mediaDir  = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        $sourceDir = rtrim($mediaDir, '/') . '/' . self::SOURCE_SUBDIR;
        $destRoot  = rtrim($mediaDir, '/') . '/' . self::DEST_ROOT;

        foreach ($rows as $row) {
            $stats['checked']++;
            $imagePath = $row['image_path']; // e.g. "/l/t/foo.jpg", leading slash as Magento stores it

            if (!$imagePath) {
                continue; // no main image set for this product — nothing to compress
            }

            $sourcePath = $sourceDir . $imagePath;

            if (!is_file($sourcePath)) {
                $stats['missing']++;
                $this->logger->warning(sprintf(
                    '[SmartSearch][ImageCompress] sku=%s entity_id=%d — source image file missing on disk: %s',
                    $row['sku'], $row['entity_id'], $sourcePath
                ));
                continue;
            }

            $destPath = $this->buildDestPath($destRoot, $imagePath);

            if (is_file($destPath) && filemtime($destPath) >= filemtime($sourcePath)) {
                $stats['skipped']++;
                continue;
            }

            if ($this->engine->compress($sourcePath, $destPath)) {
                $stats['compressed']++;
            } else {
                $stats['failed']++;
                $this->logger->error(sprintf(
                    '[SmartSearch][ImageCompress] sku=%s entity_id=%d — compression failed for %s',
                    $row['sku'], $row['entity_id'], $sourcePath
                ));
            }
        }

        return $stats;
    }

    /**
     * pub/media/falcosense/800x800/{c1}/{c2}/{filename} — same bucketing
     * style as the Klevu-image-cache reference script, but a hardcoded
     * 800x800 tier rather than the source's actual dimensions; the engine
     * resizes every image to fit within that box before encoding.
     */
    private function buildDestPath(string $destRoot, string $imagePath): string
    {
        $filename = basename($imagePath);
        $c1 = $filename[0];
        $c2 = $filename[1] ?? $c1;

        return "{$destRoot}/" . self::DEST_BUCKET . "/{$c1}/{$c2}/{$filename}";
    }

    /**
     * Enabled products only, newest-updated first. When $sinceUpdatedAt is
     * null this is a full run — still newest-first, so the most recently
     * changed products are guaranteed to be covered even if a run is cut
     * short by its limit.
     *
     * @return array<int, array{entity_id:int, sku:string, image_path:?string, updated_at:string}>
     */
    private function fetchCandidateProducts(?string $sinceUpdatedAt, int $limit): array
    {
        $conn = $this->resource->getConnection();

        $select = $conn->select()
            ->from(['p' => $conn->getTableName('catalog_product_entity')], ['entity_id', 'sku', 'updated_at'])
            ->join(
                ['a' => $conn->getTableName('eav_attribute')],
                "a.entity_type_id = 4 AND a.attribute_code = 'image'",
                []
            )
            ->joinLeft(
                ['v' => $conn->getTableName('catalog_product_entity_varchar')],
                'v.attribute_id = a.attribute_id AND v.entity_id = p.entity_id',
                ['image_path' => 'v.value']
            )
            ->join(
                ['s' => $conn->getTableName('catalog_product_entity_int')],
                "s.entity_id = p.entity_id AND s.attribute_id = (
                    SELECT attribute_id FROM {$conn->getTableName('eav_attribute')}
                    WHERE entity_type_id = 4 AND attribute_code = 'status'
                ) AND s.value = " . Status::STATUS_ENABLED,
                []
            )
            ->order('p.updated_at DESC')
            ->limit($limit);

        if ($sinceUpdatedAt !== null) {
            $select->where('p.updated_at > ?', $sinceUpdatedAt);
        }

        $result = [];
        foreach ($conn->fetchAll($select) as $row) {
            $imagePath = $row['image_path'] ?? null;
            if ($imagePath === 'no_selection') {
                $imagePath = null;
            }
            $result[] = [
                'entity_id'  => (int) $row['entity_id'],
                'sku'        => (string) $row['sku'],
                'image_path' => $imagePath,
                'updated_at' => (string) $row['updated_at'],
            ];
        }

        return $result;
    }
}
