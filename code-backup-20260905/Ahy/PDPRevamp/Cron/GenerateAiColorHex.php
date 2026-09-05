<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Cron;

use Ahy\PDPRevamp\Logger\Logger;
use Ahy\PDPRevamp\Service\AiColorHexResolver;
use Ahy\PDPRevamp\Service\GeminiClient;

/**
 * Scheduled counterpart to Console\Command\GenerateAiColorHex - keeps the
 * native color-swatch hex (eav_attribute_option_swatch) current as new
 * `color` option values get added in admin, without requiring anyone to
 * remember to run the console command by hand.
 */
class GenerateAiColorHex
{
    private AiColorHexResolver $resolver;
    private GeminiClient $geminiClient;
    private Logger $logger;

    public function __construct(AiColorHexResolver $resolver, GeminiClient $geminiClient, Logger $logger)
    {
        $this->resolver = $resolver;
        $this->geminiClient = $geminiClient;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->geminiClient->isConfigured()) {
            // No key configured - nothing to do, not an error (mirrors GenerateAiDescriptors' console check).
            return;
        }

        try {
            $missing = $this->resolver->getMissingColorOptions();
            if (!$missing) {
                return;
            }

            $results = $this->resolver->backfill($missing);

            $counts = ['resolved' => 0, 'failed' => 0];
            foreach ($results as $result) {
                $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            }

            $this->logger->info(sprintf(
                '[GenerateAiColorHex cron] Checked %d option(s). Resolved: %d, Failed: %d.',
                count($missing),
                $counts['resolved'] ?? 0,
                $counts['failed'] ?? 0
            ));
        } catch (\Throwable $exception) {
            $this->logger->error('[GenerateAiColorHex cron] Run failed: ' . $exception->getMessage());
        }
    }
}
