<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Service;

use Ahy\PDPRevamp\Logger\Logger;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Saves a review photo (submitted by the storefront as a base64 data URL -
 * see Controller\Index\Review) to our own public media storage, so it has a
 * real URL Yotpo's images/process endpoint can fetch - Yotpo's API accepts
 * only publicly reachable URLs, never base64 data or a file upload directly.
 */
class ReviewPhotoUploader
{
    private const SUBDIR = 'yotpo/reviews';

    /** Mirrors the frontend's own 10MB/photo cap (yotpo_js.phtml's addPhotos()) - enforced here too since that check is trivially bypassable. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private Filesystem $filesystem;
    private StoreManagerInterface $storeManager;
    private Logger $logger;

    public function __construct(Filesystem $filesystem, StoreManagerInterface $storeManager, Logger $logger)
    {
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @param string $dataUrl "data:image/jpeg;base64,..." as sent by the
     *  frontend's FileReader.readAsDataURL()
     * @param string $originalName the uploaded file's original name, for
     *  logging only
     * @return string|null the public URL, or null if the data isn't a
     *  supported image type, isn't valid base64, exceeds the size cap, or
     *  fails to save - never throws, since one bad photo must not fail the
     *  whole review submission
     */
    public function saveBase64Photo(string $dataUrl, string $originalName): ?string
    {
        if (!preg_match('/^data:(image\/(?:jpeg|png|webp));base64,(.+)$/', $dataUrl, $matches)) {
            $this->logger->error('[ReviewPhotoUploader] Rejected "' . $originalName . '": not a supported image data URL');
            return null;
        }

        $extension = self::ALLOWED_MIME_EXTENSIONS[$matches[1]] ?? null;
        if ($extension === null) {
            return null;
        }

        $decoded = base64_decode($matches[2], true);
        if ($decoded === false || $decoded === '') {
            $this->logger->error('[ReviewPhotoUploader] Rejected "' . $originalName . '": could not decode base64 data');
            return null;
        }

        if (strlen($decoded) > self::MAX_BYTES) {
            $this->logger->error('[ReviewPhotoUploader] Rejected "' . $originalName . '": exceeds ' . self::MAX_BYTES . ' bytes');
            return null;
        }

        $subDir = self::SUBDIR . '/' . date('Y') . '/' . date('m');
        $filename = uniqid('review_', true) . '.' . $extension;
        $relativePath = $subDir . '/' . $filename;

        try {
            $mediaDir = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $mediaDir->create($subDir);
            $mediaDir->writeFile($relativePath, $decoded);
        } catch (\Throwable $exception) {
            $this->logger->error('[ReviewPhotoUploader] Failed to save "' . $originalName . '": ' . $exception->getMessage());
            return null;
        }

        $store = $this->storeManager->getStore();
        $mediaBaseUrl = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA, $store->isCurrentlySecure()), '/');

        return $mediaBaseUrl . '/' . $relativePath;
    }
}
