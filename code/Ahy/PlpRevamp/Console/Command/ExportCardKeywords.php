<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Console\Command;

use Ahy\PlpRevamp\Model\Import\CardKeywordsImporter;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DirectoryList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes every category to a CSV in the Magento root, in exactly the shape the
 * Subcategory Keywords importer reads back.
 *
 * Round-trip by design: export, fill in the content columns, re-upload through
 * Ahy > PLP Keywords Import. Columns are pre-filled with current values, so an
 * export doubles as a backup before a bulk edit. The column list is taken from
 * CardKeywordsImporter::FIELDS, so adding a field there updates both ends.
 *
 * full_path is included because this tree has duplicated names — "ADP Crew Socks"
 * exists at 3227 and 3235 — and a flat name column gives no way to tell which row
 * is which. The importer ignores columns it does not recognise, so the extra
 * context costs nothing on the way back in.
 */
class ExportCardKeywords extends Command
{
    private const ARG_FILE     = 'file';
    private const OPT_STORE    = 'store';
    private const DEFAULT_FILE = 'category-keywords.csv';

    /** Root and store-root carry no cards of their own. */
    private const MIN_LEVEL = 2;

    public function __construct(
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly DirectoryList $directoryList,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ahy:plp:card-keywords:export')
            ->setDescription('Export category ids, names and current PLP content (keywords, featured description) to a CSV in the Magento root.')
            ->addArgument(
                self::ARG_FILE,
                InputArgument::OPTIONAL,
                'Output file. Relative paths are resolved from the Magento root.',
                self::DEFAULT_FILE
            )
            ->addOption(
                self::OPT_STORE,
                's',
                InputOption::VALUE_REQUIRED,
                'Store id to read keywords for. 0 = default scope.',
                0
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Already set by the CLI bootstrap — fine.
        }

        $storeId = (int) $input->getOption(self::OPT_STORE);
        $target  = $this->resolvePath((string) $input->getArgument(self::ARG_FILE));

        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect(array_merge(['name'], array_values(CardKeywordsImporter::FIELDS)))
            ->addAttributeToSort('path', 'ASC');

        // id => name for every category, so ancestor names resolve without a query per row.
        $names = [];
        foreach ($collection as $category) {
            $names[(int) $category->getId()] = (string) $category->getName();
        }

        $handle = @fopen($target, 'w');
        if ($handle === false) {
            $output->writeln(sprintf('<error>Could not write to %s</error>', $target));
            return Command::FAILURE;
        }

        // UTF-8 BOM: without it Excel mangles names like "WetHoodie(tm)". The importer
        // strips it back off, so the round trip stays clean.
        fwrite($handle, "\xEF\xBB\xBF");
        // Content columns come straight from the importer's map, so export and
        // import can never disagree about the header.
        $contentColumns = array_keys(CardKeywordsImporter::FIELDS);
        fputcsv($handle, array_merge(['category_id', 'category_name'], $contentColumns, ['level', 'full_path']));

        $rows = 0;
        foreach ($collection as $category) {
            if ((int) $category->getLevel() < self::MIN_LEVEL) {
                continue;
            }

            $values = [];
            foreach ($contentColumns as $column) {
                $values[] = (string) $category->getData(CardKeywordsImporter::FIELDS[$column]);
            }

            fputcsv($handle, array_merge(
                [(int) $category->getId(), (string) $category->getName()],
                $values,
                [(int) $category->getLevel(), $this->buildPath((string) $category->getPath(), $names)]
            ));
            $rows++;
        }

        fclose($handle);

        $output->writeln(sprintf('<info>Wrote %d categories to %s</info>', $rows, $target));
        $output->writeln('<comment>Fill in the content columns, then upload it under Ahy > PLP Keywords Import.</comment>');

        return Command::SUCCESS;
    }

    /** Relative paths land in the Magento root, absolute ones are honoured as given. */
    private function resolvePath(string $file): string
    {
        if ($file === '') {
            $file = self::DEFAULT_FILE;
        }
        if (str_starts_with($file, '/')) {
            return $file;
        }
        return rtrim($this->directoryList->getRoot(), '/') . '/' . $file;
    }

    /**
     * "Default Category > Hunting > Shirts" from the category's path ids.
     *
     * @param array<int,string> $names
     */
    private function buildPath(string $path, array $names): string
    {
        $segments = [];
        foreach (explode('/', $path) as $id) {
            $id = (int) $id;
            // Skip the tree root (id 1) — noise on every single row.
            if ($id <= 1 || !isset($names[$id])) {
                continue;
            }
            $segments[] = $names[$id];
        }
        return implode(' > ', $segments);
    }
}
