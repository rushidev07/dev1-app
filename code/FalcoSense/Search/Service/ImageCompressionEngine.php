<?php
declare(strict_types=1);

namespace FalcoSense\Search\Service;

use Psr\Log\LoggerInterface;

/**
 * Re-encodes a single image file with a high-quality/lossless setting, GD only
 * (no Imagick on this server), never touching the source file: writes to a
 * new destination path only.
 *
 * Also resizes to fit within an 800x800 box — aspect ratio preserved, never
 * upscaled (an image already <= 800x800 on both axes is left at its own
 * size), never cropped or stretched. An image larger than 800x800 on either
 * axis is scaled down so its larger side hits 800 and the other side follows
 * proportionally.
 *
 * Output format is driven by the *decoded* image type (via getimagesize), not
 * the file extension — a mislabeled file (e.g. a PNG saved as .jpg) still
 * round-trips through the correct decoder/encoder pair.
 */
class ImageCompressionEngine
{
    private const JPEG_QUALITY  = 92;
    private const PNG_LEVEL     = 6; // lossless — level only affects speed/size, not quality
    private const WEBP_QUALITY  = 92;
    private const MAX_DIMENSION = 800;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @return bool true if a compressed (or, as a last-resort fallback, verbatim-copied)
     *              file now exists at $destPath.
     */
    public function compress(string $sourcePath, string $destPath): bool
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $this->logger->warning("[SmartSearch][ImageCompress] Source missing/unreadable: {$sourcePath}");
            return false;
        }

        $info = @getimagesize($sourcePath);
        $type = $info[2] ?? null;

        if ($type === null) {
            $this->logger->warning("[SmartSearch][ImageCompress] Not a decodable image, copying as-is: {$sourcePath}");
            return $this->fallbackCopy($sourcePath, $destPath);
        }

        if (!$this->ensureDestDir($destPath)) {
            $this->logger->error("[SmartSearch][ImageCompress] Could not create destination dir for: {$destPath}");
            return false;
        }

        $tmpPath = $destPath . '.tmp-' . getmypid();

        try {
            $ok = match ($type) {
                IMAGETYPE_JPEG => $this->encodeJpeg($sourcePath, $tmpPath),
                IMAGETYPE_PNG  => $this->encodePng($sourcePath, $tmpPath),
                IMAGETYPE_WEBP => $this->encodeWebp($sourcePath, $tmpPath),
                default        => null, // unsupported type — fall through to verbatim copy
            };
        } catch (\Throwable $e) {
            $this->logger->error("[SmartSearch][ImageCompress] Encode error for {$sourcePath}: " . $e->getMessage());
            $ok = false;
        }

        if ($ok === null) {
            $this->logger->info("[SmartSearch][ImageCompress] Unsupported/unknown image type ({$type}), copying as-is: {$sourcePath}");
            @unlink($tmpPath);
            return $this->fallbackCopy($sourcePath, $destPath);
        }

        if ($ok === false || !is_file($tmpPath) || filesize($tmpPath) === 0) {
            @unlink($tmpPath);
            $this->logger->error("[SmartSearch][ImageCompress] Re-encode failed, falling back to verbatim copy: {$sourcePath}");
            return $this->fallbackCopy($sourcePath, $destPath);
        }

        // A "compressed" file must never be bigger than the source — GD drops
        // palette optimization to truecolor on PNG decode, and a fixed high
        // JPEG quality can exceed the source's own quality, both of which can
        // inflate size with zero visual gain. If re-encoding didn't actually
        // shrink it, keep the original bytes instead — never a regression.
        if (filesize($tmpPath) >= filesize($sourcePath)) {
            @unlink($tmpPath);
            return $this->fallbackCopy($sourcePath, $destPath);
        }

        // Atomic swap-in — avoids ever leaving a half-written file at $destPath.
        if (!rename($tmpPath, $destPath)) {
            @unlink($tmpPath);
            $this->logger->error("[SmartSearch][ImageCompress] Could not move tmp file into place: {$destPath}");
            return false;
        }

        return true;
    }

    private function encodeJpeg(string $sourcePath, string $tmpPath): bool
    {
        $src = @imagecreatefromjpeg($sourcePath);
        if ($src === false) {
            return false;
        }
        // JPEG has no alpha channel — nothing to preserve there.
        $img = $this->resizeToFit($src, false);
        $ok  = imagejpeg($img, $tmpPath, self::JPEG_QUALITY);
        imagedestroy($img);
        return $ok;
    }

    private function encodePng(string $sourcePath, string $tmpPath): bool
    {
        $src = @imagecreatefrompng($sourcePath);
        if ($src === false) {
            return false;
        }
        // Preserve transparency — PNG re-encode is lossless regardless of level.
        imagealphablending($src, false);
        imagesavealpha($src, true);
        $img = $this->resizeToFit($src, true);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $ok = imagepng($img, $tmpPath, self::PNG_LEVEL);
        imagedestroy($img);
        return $ok;
    }

    private function encodeWebp(string $sourcePath, string $tmpPath): bool
    {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
            $this->logger->warning('[SmartSearch][ImageCompress] GD build has no WEBP support — falling back to verbatim copy.');
            return false;
        }
        $src = @imagecreatefromwebp($sourcePath);
        if ($src === false) {
            return false;
        }
        imagealphablending($src, false);
        imagesavealpha($src, true);
        $img = $this->resizeToFit($src, true);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $ok = imagewebp($img, $tmpPath, self::WEBP_QUALITY);
        imagedestroy($img);
        return $ok;
    }

    /**
     * Fit within 800x800, aspect ratio preserved — never upscales (an image
     * already within the box on both axes is returned untouched) and never
     * crops or stretches. Returns a NEW GD image when it resizes (and
     * destroys $src), or $src itself unchanged when no resize is needed —
     * callers must not double-free $src in that case.
     */
    private function resizeToFit(\GdImage $src, bool $preserveAlpha): \GdImage
    {
        $width  = imagesx($src);
        $height = imagesy($src);

        if ($width <= self::MAX_DIMENSION && $height <= self::MAX_DIMENSION) {
            return $src;
        }

        $ratio     = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);
        $newWidth  = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $dst = imagecreatetruecolor($newWidth, $newHeight);

        if ($preserveAlpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);

        return $dst;
    }

    /**
     * Guarantees the module always has *a* usable local copy of the image,
     * even when it can't be re-encoded (corrupt, unsupported, no WEBP
     * support in this GD build, etc.) — never leaves a product with no
     * image at all in its own compressed-images tree.
     */
    private function fallbackCopy(string $sourcePath, string $destPath): bool
    {
        if (!$this->ensureDestDir($destPath)) {
            return false;
        }
        return @copy($sourcePath, $destPath);
    }

    private function ensureDestDir(string $destPath): bool
    {
        $dir = dirname($destPath);
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }
}
