<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Ahy\PDPRevamp\Model\ResourceModel\Faq\CollectionFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the generic/fallback FAQ rows (product_id IS NULL) shown on any
 * product that has no FAQs of its own. Idempotent - does nothing if
 * generic rows already exist (e.g. re-running on an environment that
 * already has this data).
 */
class CreateGenericFaqData implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private CollectionFactory $collectionFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        CollectionFactory $collectionFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->collectionFactory = $collectionFactory;
    }

    public function apply(): self
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('product_id', ['null' => true]);
        if ($collection->getSize() > 0) {
            return $this;
        }

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('ahy_pdprevamp_faq');

        $rows = [
            [
                'product_id' => null,
                'category' => 'Sizing',
                'question' => 'How do I find the right size?',
                'answer' => 'Check the size chart on this page for measurements specific to this product. If you are between sizes, we generally recommend sizing up for a more comfortable fit. Still unsure? Our gear experts are happy to help.',
                'sort_order' => 10,
                'is_active' => 1,
            ],
            [
                'product_id' => null,
                'category' => 'Materials',
                'question' => 'What materials is this product made from?',
                'answer' => 'Material details are listed in the Specifications tab above. We prioritize durable, high-quality materials designed to hold up to real outdoor use.',
                'sort_order' => 20,
                'is_active' => 1,
            ],
            [
                'product_id' => null,
                'category' => 'Safety',
                'question' => 'Is this product safe for the intended use?',
                'answer' => 'Yes - all our products are tested to meet relevant safety standards for their category. Please always follow the usage guidelines included with your product.',
                'sort_order' => 30,
                'is_active' => 1,
            ],
            [
                'product_id' => null,
                'category' => 'Features',
                'question' => 'What key features should I know about?',
                'answer' => 'See the Details tab above for a full breakdown of this product\'s features. If you have a specific question that is not answered there, reach out to our team and we will help.',
                'sort_order' => 40,
                'is_active' => 1,
            ],
            [
                'product_id' => null,
                'category' => 'Policy',
                'question' => 'What is your return policy for this product?',
                'answer' => 'We offer 30-day hassle-free returns. Items must be in original condition with tags attached. See the Shipping & Returns section above for full details.',
                'sort_order' => 50,
                'is_active' => 1,
            ],
            [
                'product_id' => null,
                'category' => 'Everest',
                'question' => 'How does the Everest Give Back Fund work with my purchase?',
                'answer' => 'A portion of every purchase supports outdoor conservation through the Everest Give Back Fund, helping protect the wild spaces we all love to explore.',
                'sort_order' => 60,
                'is_active' => 1,
            ],
        ];

        $connection->insertMultiple($table, $rows);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
