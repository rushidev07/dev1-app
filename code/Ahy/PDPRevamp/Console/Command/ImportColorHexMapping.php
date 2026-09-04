<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Console\Command;

use Ahy\PDPRevamp\Service\AiColorHexResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bulk-imports a color name -> hex code mapping from a CSV file straight
 * into Magento's native visual swatch (eav_attribute_option_swatch),
 * skipping AI/CSS resolution entirely - for when the exact hex values are
 * already known (e.g. a brand's official color chart) rather than guessed.
 * Complements Console\Command\GenerateAiColorHex, which resolves colors
 * that don't have a known hex yet.
 */
class ImportColorHexMapping extends Command
{
    private const OPTION_CSV = 'csv';
    private const OPTION_OVERWRITE = 'overwrite';
    private const OPTION_DRY_RUN = 'dry-run';

    private const COLUMN_NAME = 'color_name';
    private const COLUMN_HEX = 'hex_code';

    private AiColorHexResolver $resolver;

    public function __construct(AiColorHexResolver $resolver)
    {
        $this->resolver = $resolver;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:pdp:import-color-hex-mapping')
            ->setDescription(
                'Bulk-import a "color_name,hex_code" CSV directly into the native color swatch, no AI involved'
            )
            ->addOption(
                self::OPTION_CSV,
                null,
                InputOption::VALUE_REQUIRED,
                'Path to a CSV file with "color_name" and "hex_code" columns (header row required)'
            )
            ->addOption(
                self::OPTION_OVERWRITE,
                null,
                InputOption::VALUE_NONE,
                'Re-save even options that already have a native swatch set'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print what would be imported without saving anything'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getOption(self::OPTION_CSV);
        if ($path === '') {
            $output->writeln('<error>--csv=/path/to/file.csv is required.</error>');
            return 1;
        }

        if (!is_readable($path)) {
            $output->writeln("<error>Cannot read CSV file: $path</error>");
            return 1;
        }

        $nameToHex = $this->readCsv($path, $output);
        if ($nameToHex === null) {
            return 1;
        }

        if (!$nameToHex) {
            $output->writeln('<comment>Nothing to import - CSV had no data rows.</comment>');
            return 0;
        }

        $overwrite = (bool) $input->getOption(self::OPTION_OVERWRITE);
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);

        $output->writeln('<info>Read ' . count($nameToHex) . ' row(s) from CSV.</info>');

        $result = $this->resolver->importMapping($nameToHex, $overwrite, $dryRun);

        foreach ($result['invalid_hex'] as $name) {
            $output->writeln("<comment>[$name] Invalid hex code - skipped.</comment>");
        }
        foreach ($result['unmatched'] as $name) {
            $output->writeln("<comment>[$name] No matching \"color\" attribute option - skipped.</comment>");
        }

        $output->writeln(sprintf(
            '<info>Done. Matched: %d, Saved: %d%s, Skipped (already set): %d, Invalid hex: %d, Unmatched: %d.</info>',
            $result['matched'],
            $dryRun ? $result['would_save'] : $result['saved'],
            $dryRun ? ' (dry-run)' : '',
            $result['skipped_already_set'],
            count($result['invalid_hex']),
            count($result['unmatched'])
        ));

        return 0;
    }

    /**
     * @return array<string, string>|null color_name => hex_code, or null on a fatal format error
     */
    private function readCsv(string $path, OutputInterface $output): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            $output->writeln("<error>Could not open CSV file: $path</error>");
            return null;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            $output->writeln('<error>CSV file is empty.</error>');
            return null;
        }

        $header = array_map(static fn ($col) => strtolower(trim((string) $col)), $header);
        $nameIndex = array_search(self::COLUMN_NAME, $header, true);
        $hexIndex = array_search(self::COLUMN_HEX, $header, true);

        if ($nameIndex === false || $hexIndex === false) {
            fclose($handle);
            $output->writeln(sprintf(
                '<error>CSV must have "%s" and "%s" columns. Found: %s</error>',
                self::COLUMN_NAME,
                self::COLUMN_HEX,
                implode(', ', $header)
            ));
            return null;
        }

        $nameToHex = [];
        while (($row = fgetcsv($handle)) !== false) {
            $name = trim((string) ($row[$nameIndex] ?? ''));
            $hex = trim((string) ($row[$hexIndex] ?? ''));
            if ($name === '' || $hex === '') {
                continue;
            }
            $nameToHex[$name] = $hex;
        }

        fclose($handle);

        return $nameToHex;
    }
}
