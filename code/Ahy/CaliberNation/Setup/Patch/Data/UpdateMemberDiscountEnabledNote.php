<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Refreshes the admin help text under "Enable Member Discount" on the product form.
 *
 * The note is stored in eav_attribute.note at attribute-creation time, and
 * AddMemberDiscountEnabledAttribute is guarded by an existence check — so editing the
 * string there only reaches FRESH installs. This patch updates environments where the
 * attribute already exists (dev/staging/production) so the wording matches everywhere.
 */
class UpdateMemberDiscountEnabledNote implements DataPatchInterface
{
    private const NOTE = 'Turn ON for the product-level member discount for additional discount'
        . ' to apply. Turn OFF to pause it without losing the type/value.';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributeCode = AddMemberDiscountEnabledAttribute::ATTRIBUTE_CODE;

        // No-op when the attribute isn't there yet — the add patch will create it with
        // the current wording already baked in.
        if (!$eavSetup->getAttributeId(Product::ENTITY, $attributeCode)) {
            return $this;
        }

        $eavSetup->updateAttribute(Product::ENTITY, $attributeCode, 'note', self::NOTE);

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [AddMemberDiscountEnabledAttribute::class];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
