<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model;

use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Converts the feature-icon "image" value between DB storage form (a plain
 * media-relative path string) and admin form/UI-component form (an array of
 * one file-info object, matching Magento's stock imageUploader element).
 */
class RowImageProcessor
{
    public function __construct(
        private readonly ImageUploader $imageUploader,
        private readonly StoreManagerInterface $storeManager
    ) {}

    /**
     * Expand a stored path into the array shape the imageUploader form
     * element expects.
     */
    public function toFormValue(string $path): array
    {
        if ($path === '') {
            return [];
        }

        return [[
            'name' => basename($path),
            'url'  => $this->getMediaBaseUrl() . ltrim($path, '/'),
        ]];
    }

    /**
     * Collapse the imageUploader element's submitted array shape back down
     * to a plain media-relative path string, moving newly uploaded files
     * out of the tmp directory in the process.
     *
     * @param mixed $value
     * @throws LocalizedException
     */
    public function toStorageValue($value): string
    {
        if (!is_array($value) || empty($value[0])) {
            return '';
        }

        $file = $value[0];
        $name = $file['name'] ?? null;
        if (!$name) {
            return '';
        }

        if (!empty($file['tmp_name'])) {
            // Newly uploaded in this request; move out of tmp storage.
            return $this->imageUploader->moveFileFromTmp($name, true);
        }

        // Unchanged existing image; $name is just the basename.
        return rtrim($this->imageUploader->getBasePath(), '/') . '/' . ltrim($name, '/');
    }

    private function getMediaBaseUrl(): string
    {
        return $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
    }
}
