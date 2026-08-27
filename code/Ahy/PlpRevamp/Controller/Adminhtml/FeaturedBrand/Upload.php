<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Controller\Adminhtml\FeaturedBrand;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Catalog\Model\ImageUploader;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;

/**
 * Uploads a featured-brand image to the tmp media dir.
 *
 * The fileUploader lives inside a dynamicRows record, so the browser posts the file under a
 * multi-level name such as `ahy_featured_brands[0][image]`. The core Uploader does not parse
 * multi-level names, so we flatten the single uploaded file to $_FILES['image'] first.
 */
class Upload extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Catalog::categories';

    private const FILE_ID = 'image';

    public function __construct(
        Context $context,
        private readonly ImageUploader $imageUploader
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $file = $this->extractUploadedFile();
        if ($file !== null) {
            $_FILES[self::FILE_ID] = $file;
        }

        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        try {
            $data = $this->imageUploader->saveFileToTmpDir(self::FILE_ID);
            unset($data['path'], $data['tmp_name']);
            return $result->setData($data);
        } catch (\Exception $e) {
            return $result->setData(['error' => $e->getMessage(), 'errorcode' => $e->getCode()]);
        }
    }

    /**
     * Flatten the single uploaded file out of a (possibly multi-level) $_FILES entry.
     *
     * @return array<string, mixed>|null
     */
    private function extractUploadedFile(): ?array
    {
        foreach ($_FILES as $field => $data) {
            if (!is_array($data) || !isset($data['name'])) {
                continue;
            }
            if ($field === self::FILE_ID && !is_array($data['name'])) {
                return $data; // already flat
            }

            $path = $this->findLeafPath($data['name']);
            if ($path === null) {
                continue;
            }

            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $file[$key] = isset($data[$key]) ? $this->valueByPath($data[$key], $path) : null;
            }
            if (!empty($file['name'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Return the array key path down to the first non-empty scalar leaf.
     *
     * @param mixed $value
     * @return array<int, int|string>|null
     */
    private function findLeafPath($value): ?array
    {
        if (!is_array($value)) {
            return ($value === null || $value === '') ? null : [];
        }
        foreach ($value as $key => $child) {
            $sub = $this->findLeafPath($child);
            if ($sub !== null) {
                return array_merge([$key], $sub);
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     * @param array<int, int|string> $path
     * @return mixed
     */
    private function valueByPath($value, array $path)
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
