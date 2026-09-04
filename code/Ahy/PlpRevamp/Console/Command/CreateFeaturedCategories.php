<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Console\Command;

use Ahy\PlpRevamp\Model\Category\FeaturedCategoryManager;
use Ahy\PlpRevamp\Setup\Patch\Data\AddFeaturedContainerFlagAttribute as ContainerFlag;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-off backfill: give every existing category a "Featured Products" child and
 * point its ahy_featured_source_id at it.
 *
 * Categories that already have a source id are left untouched, so the command is
 * safe to re-run.
 */
class CreateFeaturedCategories extends Command
{
    private const OPT_DRY_RUN    = 'dry-run';
    private const OPT_STORE      = 'store';
    private const OPT_ALL_STORES = 'all-stores';

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly FeaturedCategoryManager $manager,
        private readonly StoreManagerInterface $storeManager,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ahy:plp:featured-categories:create')
            ->setDescription('Create a "Featured Products" child category for every category and assign it as the Featured Products source.')
            ->addOption(
                self::OPT_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'List what would be created without writing anything.'
            )
            ->addOption(
                self::OPT_STORE,
                's',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Also write the source id at this store view id. Repeatable. Default scope (0) is always written.'
            )
            ->addOption(
                self::OPT_ALL_STORES,
                null,
                InputOption::VALUE_NONE,
                'Also write the source id at every store view, overriding per-view values.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area already set by the CLI bootstrap — fine.
        }

        $dryRun   = (bool) $input->getOption(self::OPT_DRY_RUN);
        $storeIds = $this->resolveStoreIds($input);

        $output->writeln(sprintf(
            '<info>Writing ahy_featured_source_id at store scope(s): %s</info>',
            implode(', ', $storeIds)
        ));
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — nothing will be written.</comment>');
        }

        $collection = $this->categoryCollectionFactory->create()
            ->addAttributeToSelect(['name', 'url_key', FeaturedCategoryManager::SOURCE_ATTRIBUTE, ContainerFlag::ATTRIBUTE_CODE])
            ->addAttributeToFilter('level', ['gteq' => 2])
            ->setStoreId(0)
            // The collection is loaded in full before the loop starts, so the
            // containers created during the run never re-enter it. The isEligible()
            // flag check is the guard for re-runs.
            ->addAttributeToSort('level', 'ASC')
            ->addAttributeToSort('entity_id', 'ASC');

        $created = $skipped = $failed = $linked = 0;
        $total   = $collection->count();
        $started = microtime(true);

        $output->writeln(sprintf('<info>%d categories to inspect.</info>', $total));
        $output->writeln(
            '<comment>Tip: if the catalog indexers are in "Update on Save" mode this runs far '
            . 'slower, because every category save reindexes. bin/magento indexer:set-mode schedule '
            . 'beforehand (and back afterwards) if that is the case.</comment>'
        );

        foreach ($collection as $category) {
            $reason = null;
            if (!$this->manager->isEligible($category, $reason)) {
                $skipped++;
                if ($output->isVerbose()) {
                    $output->writeln(sprintf(
                        '  <comment>skip</comment> #%d %s — %s',
                        $category->getId(),
                        $category->getName(),
                        $reason
                    ));
                }
                continue;
            }

            $existingId = $this->manager->findExistingChild($category);

            if ($dryRun) {
                $output->writeln(sprintf(
                    '  would %s under #%d %s',
                    $existingId !== null
                        ? sprintf('link existing #%d', $existingId)
                        : sprintf('create "%s"', FeaturedCategoryManager::CATEGORY_NAME),
                    $category->getId(),
                    $category->getName()
                ));
                $existingId !== null ? $linked++ : $created++;
                continue;
            }

            try {
                $childId = $this->manager->create($category, $storeIds);
                $existingId !== null ? $linked++ : $created++;
                $output->writeln(sprintf(
                    '  <info>%s</info> #%d under #%d %s   [%d/%d, %.1fs]',
                    $existingId !== null ? 'linked ' : 'created',
                    $childId,
                    $category->getId(),
                    $category->getName(),
                    $created + $linked + $skipped + $failed,
                    $total,
                    microtime(true) - $started
                ));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf(
                    '  <error>failed</error> #%d %s — %s',
                    $category->getId(),
                    $category->getName(),
                    $e->getMessage()
                ));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done in %.1fs.</info> created: %d, linked: %d, skipped: %d, failed: %d',
            microtime(true) - $started,
            $created,
            $linked,
            $skipped,
            $failed
        ));
        if (!$dryRun && ($created > 0 || $linked > 0)) {
            $output->writeln('<comment>Reindex and flush cache to see the new categories on the storefront.</comment>');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return int[] */
    private function resolveStoreIds(InputInterface $input): array
    {
        $storeIds = [0];

        if ($input->getOption(self::OPT_ALL_STORES)) {
            foreach ($this->storeManager->getStores() as $store) {
                $storeIds[] = (int) $store->getId();
            }
            return array_values(array_unique($storeIds));
        }

        foreach ((array) $input->getOption(self::OPT_STORE) as $storeId) {
            $storeIds[] = (int) $storeId;
        }

        return array_values(array_unique($storeIds));
    }
}
