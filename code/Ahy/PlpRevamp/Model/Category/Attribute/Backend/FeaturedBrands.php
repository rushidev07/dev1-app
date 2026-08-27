<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Model\Category\Attribute\Backend;

use Magento\Catalog\Model\ImageUploader;
use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Backend model for the `ahy_featured_brands` category attribute.
 *
 * Stores a list of brand cards ({name, link, image}) as a JSON string. Each row carries an
 * image uploaded through the standard category image uploader (dispersed to pub/media/catalog/category).
 *
 *  - beforeSave(): normalizes the dynamicRows form array, moves freshly-uploaded images out of the
 *                  tmp directory, and serializes the clean list to JSON.
 *  - afterLoad():  decodes JSON back to a rows array and expands each image filename into the
 *                  fileUploader preview shape [{name, url}] so the admin form re-renders correctly.
 */
class FeaturedBrands extends AbstractBackend
{
    public function __construct(
        private readonly ImageUploader $imageUploader,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function beforeSave($object)
    {
        /** @var AbstractModel $object */
        $code  = $this->getAttribute()->getName();
        $value = $object->getData($code);

        $this->logger->info('AHY_FB beforeSave: type=' . gettype($value) . ' json=' . json_encode($value));

        // Already serialized (e.g. a programmatic save) — leave untouched.
        if (is_string($value)) {
            return parent::beforeSave($object);
        }

        if (!is_array($value)) {
            $object->setData($code, null);
            return parent::beforeSave($object);
        }

        // Defensive: some form scopings wrap the rows under the attribute code again
        // (e.g. ['ahy_featured_brands' => [ ...rows... ]]). Unwrap to the row list.
        if (isset($value[$code]) && is_array($value[$code])) {
            $value = $value[$code];
        }

        $clean = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $clean[] = [
                'name'  => $name,
                'link'  => trim((string)($row['link'] ?? '')),
                'image' => $this->resolveImage($row['image'] ?? ''),
            ];
        }

        $object->setData($code, $clean !== [] ? json_encode($clean) : null);

        $this->logger->info('AHY_FB beforeSave stored=' . var_export($object->getData($code), true));

        return parent::beforeSave($object);
    }

    public function afterLoad($object)
    {
        /** @var AbstractModel $object */
        $code  = $this->getAttribute()->getName();
        $value = $object->getData($code);

        $this->logger->info('AHY_FB afterLoad: type=' . gettype($value) . ' value=' . var_export($value, true));

        if (!is_string($value) || $value === '') {
            return parent::afterLoad($object);
        }

        $rows = json_decode($value, true);
        if (!is_array($rows)) {
            return parent::afterLoad($object);
        }

        $mediaBaseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
        foreach ($rows as &$row) {
            $image = (string)($row['image'] ?? '');
            $row['image'] = $image !== ''
                ? [['name' => $image, 'url' => $mediaBaseUrl . '/catalog/category/' . ltrim($image, '/')]]
                : [];
        }
        unset($row);

        $object->setData($code, $rows);

        return parent::afterLoad($object);
    }

    /**
     * Extract the filename from a fileUploader value and move it out of tmp if freshly uploaded.
     *
     * @param mixed $image
     */
    private function resolveImage($image): string
    {
        if (is_array($image)) {
            $first = reset($image);
            $file  = is_array($first) ? (string)($first['name'] ?? $first['file'] ?? '') : (string)$first;
        } else {
            $file = (string)$image;
        }

        $file = trim($file);
        if ($file === '') {
            return '';
        }

        // Move from catalog/tmp/category to catalog/category. Existing (already-moved) images have
        // no tmp file, so moveFileFromTmp throws — that is expected and harmless.
        try {
            $this->imageUploader->moveFileFromTmp($file);
        } catch (\Exception $e) {
            $this->logger->debug('Ahy_PlpRevamp: featured brand image not in tmp (kept as-is): ' . $file);
        }

        return $file;
    }
}
