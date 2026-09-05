<?php
declare(strict_types=1);

namespace FalcoSense\Search\Console\Command;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\ProductImageCompressionService;
use Magento\Framework\Lock\LockManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImageCompressCommand extends Command
{
    private const LOCK_NAME    = 'falcosense_search_image_compress';
    private const LOCK_TIMEOUT = 3600;
    private const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly Data                            $helper,
        private readonly ProductImageCompressionService  $service,
        private readonly LockManagerInterface             $lockManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('smartsearch:image:compress')
             ->setDescription('Compress product main images into the module\'s own media tree (never touches originals).')
             ->addOption('full', null, InputOption::VALUE_NONE, 'Ignore the delta cursor — consider every enabled product, newest-updated first')
             ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max products to check in this run', self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $full  = (bool) $input->getOption('full');
        $limit = max(1, (int) $input->getOption('limit'));

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT)) {
            $output->writeln('<error>[SmartSearch] Image compression already running elsewhere — aborting.</error>');
            return 1;
        }

        try {
            $since = $full ? null : $this->helper->getImageCompressLastRunAt();

            $output->writeln(sprintf(
                '<info>[SmartSearch] Compressing images — mode: %s, limit: %d</info>',
                $full ? 'full' : 'delta since ' . ($since ?? 'beginning'),
                $limit
            ));

            $stats = $this->service->run($since, $limit);

            $output->writeln(sprintf(
                '<info>[SmartSearch] Done — checked=%d compressed=%d skipped=%d missing=%d failed=%d</info>',
                $stats['checked'], $stats['compressed'], $stats['skipped'], $stats['missing'], $stats['failed']
            ));

            if ($stats['truncated']) {
                $output->writeln(sprintf(
                    '<comment>[SmartSearch] Backlog larger than --limit=%d — cursor not advanced, re-run (or raise --limit) to continue.</comment>',
                    $limit
                ));
            } elseif (!$full && $stats['newestUpdatedAt'] !== null) {
                $this->helper->setImageCompressLastRunAt($stats['newestUpdatedAt']);
            }

            return $stats['failed'] > 0 ? 1 : 0;
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }
}
