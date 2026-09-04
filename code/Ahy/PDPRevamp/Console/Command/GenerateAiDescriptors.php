<?php

namespace Ahy\PDPRevamp\Console\Command;

use Ahy\PDPRevamp\Service\GeminiClient;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fills the pdp_descriptors attribute (admin label "AI Descriptors") via
 * Gemini for products that don't have it set yet - the "future AI step"
 * called out in Setup\Patch\Data\CreatePdpDescriptorsAttribute.
 *
 * Configurable variants never get their own Gemini call: a configurable's
 * simple children (catalog_product_super_link) are excluded from
 * independent generation, and whatever descriptor is generated for the
 * configurable parent is copied as-is onto every one of its children. This
 * keeps a jacket's color/size variants showing the same line as the parent
 * instead of each variant inventing its own.
 */
class GenerateAiDescriptors extends Command
{
    private const OPTION_SKU = 'sku';
    private const OPTION_OVERWRITE = 'overwrite';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_DRY_RUN = 'dry-run';

    /** Stay comfortably under Gemini's free-tier per-minute rate limit. */
    private const DELAY_BETWEEN_REQUESTS_MICROSECONDS = 400000;

    private State $appState;
    private CollectionFactory $collectionFactory;
    private ProductResource $productResource;
    private GeminiClient $geminiClient;
    private ProductRepositoryInterface $productRepository;
    private ResourceConnection $resourceConnection;

    public function __construct(
        State $appState,
        CollectionFactory $collectionFactory,
        ProductResource $productResource,
        GeminiClient $geminiClient,
        ProductRepositoryInterface $productRepository,
        ResourceConnection $resourceConnection
    ) {
        $this->appState = $appState;
        $this->collectionFactory = $collectionFactory;
        $this->productResource = $productResource;
        $this->geminiClient = $geminiClient;
        $this->productRepository = $productRepository;
        $this->resourceConnection = $resourceConnection;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:pdp:generate-ai-descriptors')
            ->setDescription('Populate the AI Descriptors (pdp_descriptors) attribute via Gemini')
            ->addOption(
                self::OPTION_SKU,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only process this SKU (repeatable). A configurable variant SKU redirects to its parent. '
                . 'Default: every enabled, non-variant product missing AI Descriptors.'
            )
            ->addOption(
                self::OPTION_OVERWRITE,
                null,
                InputOption::VALUE_NONE,
                'Regenerate even for products that already have AI Descriptors set'
            )
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of products to process in this run'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print what would be written without saving anything'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if (!$this->appState->getAreaCode()) {
                $this->appState->setAreaCode('adminhtml');
            }
        } catch (LocalizedException $e) {
            // Area code already set - fine.
        }

        if (!$this->geminiClient->isConfigured()) {
            $output->writeln(
                '<error>No Gemini API key configured. Set it under Stores > Configuration > '
                . 'PDP AI Descriptors before running this command.</error>'
            );
            return 1;
        }

        $skus = $input->getOption(self::OPTION_SKU);
        $overwrite = (bool) $input->getOption(self::OPTION_OVERWRITE);
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $limit = $input->getOption(self::OPTION_LIMIT);

        [$childrenByParent, $parentByChild] = $this->loadConfigurableLinks();
        $childIds = array_keys($parentByChild);

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'description', 'pdp_descriptors']);
        $collection->addAttributeToFilter('status', Product\Attribute\Source\Status::STATUS_ENABLED);

        if (!empty($skus)) {
            $targetIds = $this->resolveSkusToTargets($skus, $parentByChild, $output);
            if (empty($targetIds)) {
                $output->writeln('<comment>Nothing to process.</comment>');
                return 0;
            }
            $collection->addFieldToFilter('entity_id', ['in' => $targetIds]);
        } else {
            if (!empty($childIds)) {
                // Configurable variants are never generated independently - see class docblock.
                $collection->addFieldToFilter('entity_id', ['nin' => $childIds]);
            }
            if (!$overwrite) {
                $collection->addAttributeToFilter(
                    'pdp_descriptors',
                    [['null' => true], ['eq' => '']],
                    'left'
                );
            }
        }

        if ($limit !== null) {
            $collection->setPageSize((int) $limit);
            $collection->setCurPage(1);
        }

        $total = $collection->getSize();
        $output->writeln("<info>Found $total product(s) to process.</info>");

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $propagated = 0;

        foreach ($collection as $product) {
            $processed++;
            $sku = $product->getSku();
            $productId = (int) $product->getId();

            $existing = (string) $product->getData('pdp_descriptors');
            if ($existing !== '' && !$overwrite && empty($skus)) {
                $skipped++;
                continue;
            }

            try {
                $categoryPath = $this->getTopCategoryName($product);
                $result = $this->geminiClient->generateDescriptors(
                    (string) $product->getName(),
                    (string) $product->getData('description'),
                    $categoryPath
                );

                if ($result === null) {
                    $output->writeln("<comment>[$sku] Gemini returned no usable result - skipped.</comment>");
                    $failed++;
                    usleep(self::DELAY_BETWEEN_REQUESTS_MICROSECONDS);
                    continue;
                }

                $output->writeln("[$sku] \"$existing\" -> \"$result\"" . ($dryRun ? ' (dry-run)' : ''));

                if (!$dryRun) {
                    $product->setData('pdp_descriptors', $result);
                    $this->productResource->saveAttribute($product, 'pdp_descriptors');
                }

                $updated++;

                if (!empty($childrenByParent[$productId])) {
                    $this->propagateToChildren($childrenByParent[$productId], $result, $dryRun, $output, $sku);
                    $propagated += count($childrenByParent[$productId]);
                }
            } catch (\Throwable $exception) {
                $output->writeln("<error>[$sku] Failed: " . $exception->getMessage() . '</error>');
                $failed++;
            }

            usleep(self::DELAY_BETWEEN_REQUESTS_MICROSECONDS);
        }

        $output->writeln(sprintf(
            '<info>Done. Processed: %d, Updated: %d, Propagated to variants: %d, Skipped: %d, Failed: %d.</info>',
            $processed,
            $updated,
            $propagated,
            $skipped,
            $failed
        ));

        return 0;
    }

    /**
     * catalog_product_super_link, loaded once: parent product id -> its child ids,
     * and the reverse lookup used to redirect a --sku targeting a variant to its parent.
     *
     * @return array{0: array<int, int[]>, 1: array<int, int>}
     */
    private function loadConfigurableLinks(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from(
            $this->resourceConnection->getTableName('catalog_product_super_link'),
            ['parent_id', 'product_id']
        );
        $rows = $connection->fetchAll($select);

        $childrenByParent = [];
        $parentByChild = [];
        foreach ($rows as $row) {
            $parentId = (int) $row['parent_id'];
            $childId = (int) $row['product_id'];
            $childrenByParent[$parentId][] = $childId;
            $parentByChild[$childId] = $parentId;
        }

        return [$childrenByParent, $parentByChild];
    }

    /**
     * Resolves --sku values to the product ids to actually run Gemini on: a SKU that
     * is a configurable variant resolves to its parent's id instead of its own, since
     * variants never get their own generated descriptor.
     *
     * @param string[] $skus
     * @param array<int, int> $parentByChild
     * @return int[]
     */
    private function resolveSkusToTargets(array $skus, array $parentByChild, OutputInterface $output): array
    {
        $targetIds = [];

        foreach ($skus as $sku) {
            try {
                $product = $this->productRepository->get($sku, false, null, true);
            } catch (NoSuchEntityException $e) {
                $output->writeln("<error>[$sku] No such SKU - skipped.</error>");
                continue;
            }

            $id = (int) $product->getId();
            if (isset($parentByChild[$id])) {
                $parentId = $parentByChild[$id];
                $output->writeln(
                    "<comment>[$sku] is a configurable variant - regenerating its parent instead; "
                    . "the result will propagate back to all its variants.</comment>"
                );
                $targetIds[$parentId] = $parentId;
            } else {
                $targetIds[$id] = $id;
            }
        }

        return array_values($targetIds);
    }

    /**
     * Copies a configurable parent's freshly generated descriptor onto each of its
     * simple children as-is - children never generate their own.
     *
     * @param int[] $childIds
     */
    private function propagateToChildren(
        array $childIds,
        string $result,
        bool $dryRun,
        OutputInterface $output,
        string $parentSku
    ): void {
        foreach ($childIds as $childId) {
            try {
                $child = $this->productRepository->getById($childId);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            $childSku = $child->getSku();
            $output->writeln(
                "  [$childSku] inherits from parent [$parentSku]: \"$result\"" . ($dryRun ? ' (dry-run)' : '')
            );

            if (!$dryRun) {
                $child->setData('pdp_descriptors', $result);
                $this->productResource->saveAttribute($child, 'pdp_descriptors');
            }
        }
    }

    private function getTopCategoryName(Product $product): string
    {
        $categoryIds = $product->getCategoryIds();
        if (empty($categoryIds)) {
            return '';
        }

        $categoryCollection = $product->getCategoryCollection();
        $categoryCollection->addAttributeToSelect('name');
        $categoryCollection->setPageSize(1);

        foreach ($categoryCollection as $category) {
            return (string) $category->getName();
        }

        return '';
    }
}
