<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Console\Command;

use Ahy\PDPRevamp\Service\AiColorHexResolver;
use Ahy\PDPRevamp\Service\GeminiClient;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fills in Magento's native visual swatch (the "Swatch" color box in
 * Stores > Attributes > Product > color's "Manage Swatch" grid) for `color`
 * options that don't have one yet, via Ahy\PDPRevamp\Service\AiColorHexResolver.
 * Standard CSS names (e.g. "Red") resolve for free; creative/marketing names
 * (e.g. "Candy Apple Craw") go through Gemini. See Cron\GenerateAiColorHex
 * for the scheduled full-catalog run this command shares its logic with.
 */
class GenerateAiColorHex extends Command
{
    private const OPTION_SKU = 'sku';
    private const OPTION_OVERWRITE = 'overwrite';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_ENABLED_ONLY = 'enabled-only';

    private State $appState;
    private AiColorHexResolver $resolver;
    private GeminiClient $geminiClient;

    public function __construct(
        State $appState,
        AiColorHexResolver $resolver,
        GeminiClient $geminiClient
    ) {
        $this->appState = $appState;
        $this->resolver = $resolver;
        $this->geminiClient = $geminiClient;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ahy:pdp:generate-ai-color-hex')
            ->setDescription('Fill in the native color-swatch hex for `color` attribute options that don\'t have one yet')
            ->addOption(
                self::OPTION_SKU,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only resolve the color options used by this SKU (repeatable, configurable or simple). '
                . 'Default: every `color` option in the catalog missing a swatch.'
            )
            ->addOption(
                self::OPTION_OVERWRITE,
                null,
                InputOption::VALUE_NONE,
                'Re-resolve even options that already have a native swatch set'
            )
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of options to process in this run'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Print what would be resolved without saving anything'
            )
            ->addOption(
                self::OPTION_ENABLED_ONLY,
                null,
                InputOption::VALUE_NONE,
                'Only resolve color options used by at least one enabled product (ignored when --sku is given)'
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
        $enabledOnly = (bool) $input->getOption(self::OPTION_ENABLED_ONLY);
        $limitOption = $input->getOption(self::OPTION_LIMIT);
        $limit = $limitOption !== null ? (int) $limitOption : null;

        if (!empty($skus)) {
            $options = [];
            foreach ($skus as $sku) {
                $options += $this->resolver->getColorOptionsForSku($sku);
            }
        } elseif ($enabledOnly) {
            $options = $overwrite
                ? $this->resolver->getEnabledColorOptions()
                : $this->resolver->getMissingEnabledColorOptions();
        } else {
            $options = $overwrite ? $this->resolver->getAllColorOptions() : $this->resolver->getMissingColorOptions();
        }

        if (empty($options)) {
            $output->writeln('<comment>Nothing to process.</comment>');
            return 0;
        }

        $output->writeln('<info>Found ' . count($options) . ' color option(s) to check.</info>');

        $results = $this->resolver->backfill($options, $overwrite, $limit, $dryRun);

        $counts = ['resolved' => 0, 'dry_run' => 0, 'skipped_already_set' => 0, 'failed' => 0];
        foreach ($results as $result) {
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            if ($result['hex'] !== null) {
                $output->writeln(sprintf(
                    '[%s] "%s" -> %s%s',
                    $result['option_id'],
                    $result['label'],
                    $result['hex'],
                    $result['status'] === 'dry_run' ? ' (dry-run)' : ''
                ));
            } elseif ($result['status'] === 'failed') {
                $output->writeln("<comment>[{$result['option_id']}] \"{$result['label']}\": Gemini returned no usable hex - skipped.</comment>");
            }
        }

        $output->writeln(sprintf(
            '<info>Done. Resolved: %d, Skipped (already set): %d, Failed: %d.</info>',
            $counts['resolved'] + $counts['dry_run'],
            $counts['skipped_already_set'],
            $counts['failed']
        ));

        return 0;
    }
}
