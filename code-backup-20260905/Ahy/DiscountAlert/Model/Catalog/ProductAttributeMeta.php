<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\Catalog;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;

/**
 * Attribute IDs and backend tables for catalog product attributes.
 *
 * Everything is resolved through Magento\Eav\Model\Config so neither the entity type
 * ID nor the value table names are hardcoded, and so the framework's own attribute
 * cache is reused.
 */
class ProductAttributeMeta
{
    public function __construct(
        private readonly EavConfig $eavConfig
    ) {}

    /**
     * @throws LocalizedException When the attribute does not exist.
     */
    public function getId(string $attributeCode): int
    {
        return (int) $this->getAttribute($attributeCode)->getId();
    }

    /**
     * Table holding this attribute's values, e.g. catalog_product_entity_decimal.
     *
     * @throws LocalizedException
     */
    public function getBackendTable(string $attributeCode): string
    {
        $table = (string) $this->getAttribute($attributeCode)->getBackendTable();

        if ($table === '') {
            throw new LocalizedException(
                __('Product attribute "%1" has no backend table.', $attributeCode)
            );
        }

        return $table;
    }

    /**
     * @throws LocalizedException
     */
    private function getAttribute(string $attributeCode): \Magento\Eav\Api\Data\AttributeInterface
    {
        $attribute = $this->eavConfig->getAttribute(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            $attributeCode
        );

        if (!$attribute || !$attribute->getId()) {
            throw new LocalizedException(
                __('Required product attribute "%1" was not found.', $attributeCode)
            );
        }

        return $attribute;
    }
}
