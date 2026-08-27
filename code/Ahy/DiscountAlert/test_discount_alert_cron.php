<?php

/**
 * Manual test runner for Ahy_DiscountAlert.
 *
 * Run it from anywhere inside the Magento installation:
 *   php app/code/Ahy/DiscountAlert/test_discount_alert_cron.php
 *
 * Optional flags:
 *   --dry-run            Show matching products but do NOT record a run or send email.
 *   --threshold=50       Override the discount threshold (%) set in admin config.
 *   --email=you@test.com Override the recipient email set in admin config.
 *   --cc=a@x.com,b@y.com Override the CC recipients set in admin config.
 *   --bcc=c@z.com        Override the BCC recipients set in admin config.
 *   --limit=1000         Override the maximum products collected for this run.
 *   --show=10            Rows to print in dry-run output (default: 25).
 *
 * This delegates to Ahy\DiscountAlert\Service\AlertDispatcher — the same service the cron
 * job uses — so a successful run here means the cron path works too.
 *
 * The supported equivalent is the console command, which needs no bootstrap of its own:
 *   bin/magento ahy:discount-alert:send [--dry-run] [--threshold=..] [--email=..]
 */

declare(strict_types=1);

use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Ahy\DiscountAlert\Service\AlertDispatcher;
use Ahy\DiscountAlert\Service\AlertResult;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;

// ── CLI guard ─────────────────────────────────────────────────────────────────

// This file boots Magento and sends mail. It must never be reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

$opts = getopt('', ['dry-run', 'threshold:', 'email:', 'cc:', 'bcc:', 'limit:', 'show:']);

$dryRun            = isset($opts['dry-run']);
$thresholdOverride = isset($opts['threshold']) ? (float) $opts['threshold'] : null;
$emailOverride     = isset($opts['email']) ? trim((string) $opts['email']) : null;
$limitOverride     = isset($opts['limit']) ? (int) $opts['limit'] : null;
$show              = isset($opts['show']) ? (int) $opts['show'] : 25;

// Split a comma/semicolon/whitespace separated CLI value into a list of valid emails.
$parseEmails = static function (?string $raw): ?array {
    if ($raw === null || trim($raw) === '') {
        return null;
    }

    $parts = preg_split('/[,;\s]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return array_values(array_filter(
        array_map('trim', $parts),
        static fn (string $email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL)
    ));
};

$ccOverride  = $parseEmails(isset($opts['cc']) ? (string) $opts['cc'] : null);
$bccOverride = $parseEmails(isset($opts['bcc']) ? (string) $opts['bcc'] : null);

if ($emailOverride !== null && !filter_var($emailOverride, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERROR: --email is not a valid address.\n");
    exit(1);
}

// ── Magento bootstrap ─────────────────────────────────────────────────────────

// Walk up from this file until app/bootstrap.php turns up, so the script works whether it
// lives in the module directory or the Magento root.
$magentoRoot = __DIR__;
while ($magentoRoot !== '/' && !file_exists($magentoRoot . '/app/bootstrap.php')) {
    $magentoRoot = dirname($magentoRoot);
}

if (!file_exists($magentoRoot . '/app/bootstrap.php')) {
    fwrite(STDERR, "ERROR: Could not locate Magento root (app/bootstrap.php not found).\n");
    exit(1);
}

require $magentoRoot . '/app/bootstrap.php';

$bootstrap     = Bootstrap::create($magentoRoot, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var State $appState */
$appState = $objectManager->get(State::class);

try {
    // Email rendering resolves adminhtml templates and design configuration.
    $appState->setAreaCode(Area::AREA_ADMINHTML);
} catch (\Magento\Framework\Exception\LocalizedException) {
    // Area already set — safe to ignore.
}

// ── Run ───────────────────────────────────────────────────────────────────────

/** @var AlertDispatcher $dispatcher */
$dispatcher = $objectManager->get(AlertDispatcher::class);

echo $dryRun ? "Dry run: nothing will be recorded or sent.\n\n" : "Sending discount alert...\n\n";

try {
    $result = $dispatcher->dispatch(
        $thresholdOverride,
        $emailOverride,
        $ccOverride,
        $bccOverride,
        $limitOverride,
        $dryRun
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

// ── Report ────────────────────────────────────────────────────────────────────

$formatNumber = static fn (float $value): string
    => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

if ($result->getStatus() === AlertResult::STATUS_SKIPPED) {
    echo 'Nothing sent: ' . $result->getReason() . "\n";
    echo "\nCheck Stores > Configuration > Ahy > Discount Alert, or pass --email / --threshold.\n";
    exit(0);
}

printf(
    "%d product(s) exceed the %s%% threshold; %d collected.\n",
    $result->getMatchedTotal(),
    $formatNumber($result->getThreshold()),
    $result->getCollectedCount()
);

if ($result->isTruncated()) {
    echo "NOTE: the result set was capped by 'Maximum Products per Run'. Raise it or pass --limit.\n";
}

if ($dryRun) {
    $products = $show > 0 ? array_slice($result->getProducts(), 0, $show) : $result->getProducts();

    if ($products) {
        echo "\n";
        printf("%-28s %-42s %12s %12s %10s\n", 'SKU', 'NAME', 'PRICE', 'SPECIAL', 'DISCOUNT');
        echo str_repeat('-', 108) . "\n";

        foreach ($products as $product) {
            printf(
                "%-28s %-42s %12s %12s %9s%%\n",
                mb_strimwidth((string) $product[Collector::KEY_SKU], 0, 28, ''),
                mb_strimwidth((string) ($product[Collector::KEY_NAME] ?? ''), 0, 42, '...'),
                number_format((float) $product[Collector::KEY_PRICE], 2),
                number_format((float) $product[Collector::KEY_SPECIAL_PRICE], 2),
                number_format((float) $product[Collector::KEY_DISCOUNT_PCT], 2)
            );
        }

        if (count($result->getProducts()) > count($products)) {
            printf("\n... and %d more (use --show=N).\n", count($result->getProducts()) - count($products));
        }
    }

    echo "\nDry run complete: no run recorded, no email sent.\n";
    exit(0);
}

echo "\n";
printf("Email sent to %s.\n", $result->getRecipient());
printf("Run #%d recorded.\n", (int) $result->getRunId());

if ($result->getReviewUrl() !== '') {
    printf("Review URL: %s\n", $result->getReviewUrl());
}

echo "\nIf the email does not arrive, check var/log/ahy_discount_alert.log and your SMTP settings.\n";

exit(0);
