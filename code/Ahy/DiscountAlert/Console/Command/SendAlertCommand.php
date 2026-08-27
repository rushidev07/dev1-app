<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Console\Command;

use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Ahy\DiscountAlert\Service\AlertDispatcher;
use Ahy\DiscountAlert\Service\AlertResult;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a discount alert on demand.
 *
 * Replaces the previous standalone bootstrap script: a registered command inherits
 * dependency injection, area handling and argument parsing, and cannot be executed by
 * anything that can reach the web root.
 */
class SendAlertCommand extends Command
{
    private const NAME = 'ahy:discount-alert:send';

    private const OPTION_DRY_RUN   = 'dry-run';
    private const OPTION_THRESHOLD = 'threshold';
    private const OPTION_EMAIL     = 'email';
    private const OPTION_CC        = 'cc';
    private const OPTION_BCC       = 'bcc';
    private const OPTION_LIMIT     = 'limit';
    private const OPTION_SHOW      = 'show';
    private const OPTION_FORCE     = 'force';

    private const DEFAULT_SHOW = 25;

    public function __construct(
        private readonly AlertDispatcher $dispatcher,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Send the discount alert digest now, or preview what it would contain.')
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'List matching products without recording a run or sending mail'
            )
            ->addOption(
                self::OPTION_THRESHOLD,
                null,
                InputOption::VALUE_REQUIRED,
                'Override the configured discount threshold (%)'
            )
            ->addOption(
                self::OPTION_EMAIL,
                null,
                InputOption::VALUE_REQUIRED,
                'Override the configured recipient address'
            )
            ->addOption(
                self::OPTION_CC,
                null,
                InputOption::VALUE_REQUIRED,
                'Override CC recipients (comma separated)'
            )
            ->addOption(
                self::OPTION_BCC,
                null,
                InputOption::VALUE_REQUIRED,
                'Override BCC recipients (comma separated)'
            )
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Override the maximum number of products collected'
            )
            ->addOption(
                self::OPTION_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Send even when nothing has changed since the previous run'
            )
            ->addOption(
                self::OPTION_SHOW,
                null,
                InputOption::VALUE_REQUIRED,
                'Rows to print in dry-run output',
                (string) self::DEFAULT_SHOW
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Email rendering resolves adminhtml templates and design config.
            $result = $this->appState->emulateAreaCode(
                Area::AREA_ADMINHTML,
                fn (): AlertResult => $this->dispatch($input)
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->report($result, $input, $output);

        return Command::SUCCESS;
    }

    private function dispatch(InputInterface $input): AlertResult
    {
        return $this->dispatcher->dispatch(
            $this->optionalFloat($input, self::OPTION_THRESHOLD),
            $this->optionalString($input, self::OPTION_EMAIL),
            $this->optionalList($input, self::OPTION_CC),
            $this->optionalList($input, self::OPTION_BCC),
            $this->optionalInt($input, self::OPTION_LIMIT),
            (bool) $input->getOption(self::OPTION_DRY_RUN),
            (bool) $input->getOption(self::OPTION_FORCE)
        );
    }

    private function report(AlertResult $result, InputInterface $input, OutputInterface $output): void
    {
        if ($result->getStatus() === AlertResult::STATUS_SKIPPED) {
            $output->writeln('<comment>Nothing sent: ' . $result->getReason() . '</comment>');

            return;
        }

        if ($result->getStatus() === AlertResult::STATUS_NO_CHANGE) {
            $output->writeln(\sprintf(
                '<comment>Run #%d recorded, no email sent: %s</comment>',
                (int) $result->getRunId(),
                $result->getReason()
            ));
            $output->writeln('Use --force to send anyway.');

            return;
        }

        $output->writeln(\sprintf(
            '<info>%d product(s) exceed the %s%% threshold; %d collected.</info>',
            $result->getMatchedTotal(),
            \rtrim(\rtrim(\number_format($result->getThreshold(), 2, '.', ''), '0'), '.'),
            $result->getCollectedCount()
        ));

        if ($result->isTruncated()) {
            $output->writeln('<comment>Result set was capped by the configured maximum.</comment>');
        }

        if ($result->getStatus() === AlertResult::STATUS_DRY_RUN) {
            $this->renderPreview($result, (int) $input->getOption(self::OPTION_SHOW), $output);
            $output->writeln('<comment>Dry run: no run recorded and no email sent.</comment>');

            return;
        }

        $output->writeln(\sprintf('<info>Email sent to %s.</info>', $result->getRecipient()));
        $output->writeln(\sprintf('Run #%d recorded.', (int) $result->getRunId()));
        $output->writeln(\sprintf(
            '%d newly flagged, %d no longer flagged since the previous run.',
            $result->getNewCount(),
            $result->getRemovedCount()
        ));

        if ($result->getReviewUrl() !== '') {
            $output->writeln('Review URL: ' . $result->getReviewUrl());
        }
    }

    private function renderPreview(AlertResult $result, int $rows, OutputInterface $output): void
    {
        $products = $rows > 0 ? \array_slice($result->getProducts(), 0, $rows) : $result->getProducts();

        if (!$products) {
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['SKU', 'Name', 'Price', 'Special', 'Discount %']);

        foreach ($products as $product) {
            $table->addRow([
                (string) $product[Collector::KEY_SKU],
                (string) ($product[Collector::KEY_NAME] ?? ''),
                \number_format((float) $product[Collector::KEY_PRICE], 2),
                \number_format((float) $product[Collector::KEY_SPECIAL_PRICE], 2),
                \number_format((float) $product[Collector::KEY_DISCOUNT_PCT], 2),
            ]);
        }

        $table->render();
    }

    private function optionalString(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        return $value === null || \trim((string) $value) === '' ? null : \trim((string) $value);
    }

    private function optionalFloat(InputInterface $input, string $option): ?float
    {
        $value = $this->optionalString($input, $option);

        return $value === null ? null : (float) $value;
    }

    private function optionalInt(InputInterface $input, string $option): ?int
    {
        $value = $this->optionalString($input, $option);

        return $value === null ? null : (int) $value;
    }

    /**
     * @return string[]|null
     */
    private function optionalList(InputInterface $input, string $option): ?array
    {
        $value = $this->optionalString($input, $option);

        if ($value === null) {
            return null;
        }

        $parts = \preg_split('/[,;\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return \array_values(\array_filter(
            \array_map('trim', $parts),
            static fn (string $email): bool => (bool) \filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
    }
}
