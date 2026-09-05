<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Product\Attribute\Backend;

use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Backing for the "key_features" attribute: lets the admin UI (see
 * Ui/DataProvider/Product/Form/Modifier/KeyFeatures.php) work with a plain
 * array of {title, description} rows, while the attribute itself stores a
 * single JSON string - so it's an ordinary EAV text attribute (saved and
 * loaded through Magento's normal product save/load, no custom table or
 * controller plugin needed) that happens to hold unlimited rows.
 */
class KeyFeatures extends AbstractBackend
{
    private Json $json;

    public function __construct(Json $json)
    {
        $this->json = $json;
    }

    public function beforeSave($object)
    {
        $attributeCode = $this->getAttribute()->getAttributeCode();
        $rows = $object->getData($attributeCode);

        if (is_array($rows)) {
            $object->setData($attributeCode, $this->json->serialize($this->normalizeRows($rows)));
        }

        return parent::beforeSave($object);
    }

    public function afterLoad($object)
    {
        $attributeCode = $this->getAttribute()->getAttributeCode();
        $object->setData($attributeCode, $this->decode($object->getData($attributeCode)));

        return parent::afterLoad($object);
    }

    /**
     * @return array<int, array{title: string, description: string}>
     */
    private function decode($value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $rows = $this->json->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($rows) ? $this->normalizeRows($rows) : [];
    }

    /**
     * Drops rows missing a title or description, matching the
     * graceful-degradation pattern used elsewhere in this module.
     *
     * @return array<int, array{title: string, description: string}>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $row = $row instanceof DataObject ? $row->getData() : $row;
            $title = trim((string) ($row['title'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));

            if ($title === '' || $description === '') {
                continue;
            }

            $normalized[] = ['title' => $title, 'description' => $description];
        }

        return $normalized;
    }
}
