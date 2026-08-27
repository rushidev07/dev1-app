<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Csv;

use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Ahy\DiscountAlert\Model\Discount\BracketProvider;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Writes the alert's full product list to a CSV under var/, one row at a time, so peak
 * memory stays flat regardless of how many products a run flags.
 */
class DiscountCsvWriter
{
    public const SUBDIRECTORY = 'ahy_discount_alert';

    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly BracketProvider $bracketProvider
    ) {}

    /**
     * @param  array<int, array<string, mixed>> $products
     * @return string Path of the written file, relative to the var directory.
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function write(array $products, float $threshold, string $fileName): string
    {
        $directory    = $this->getDirectory();
        $relativePath = self::SUBDIRECTORY . '/' . $fileName;

        $directory->create(self::SUBDIRECTORY);
        $file = $directory->openFile($relativePath, 'w+');

        try {
            // Excel needs the BOM to read UTF-8 product names correctly.
            $file->write(self::UTF8_BOM);
            $file->writeCsv([
                (string) __('Rank'),
                (string) __('SKU'),
                (string) __('Product Name'),
                (string) __('Regular Price'),
                (string) __('Sale Price'),
                (string) __('Discount (%)'),
                (string) __('Bracket'),
            ]);

            $rank = 0;
            foreach ($products as $product) {
                $rank++;
                $discount = (float) $product[Collector::KEY_DISCOUNT_PCT];
                $name     = (string) ($product[Collector::KEY_NAME] ?: $product[Collector::KEY_SKU]);

                $file->writeCsv([
                    $rank,
                    (string) $product[Collector::KEY_SKU],
                    \html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    \number_format((float) $product[Collector::KEY_PRICE], 2, '.', ''),
                    \number_format((float) $product[Collector::KEY_SPECIAL_PRICE], 2, '.', ''),
                    \number_format($discount, 2, '.', ''),
                    $this->bracketProvider->getLabelFor($discount, $threshold),
                ]);
            }
        } finally {
            $file->close();
        }

        return $relativePath;
    }

    /**
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function read(string $relativePath): string
    {
        return (string) $this->getDirectory()->readFile($relativePath);
    }

    public function delete(string $relativePath): void
    {
        try {
            $directory = $this->getDirectory();

            if ($directory->isExist($relativePath)) {
                $directory->delete($relativePath);
            }
        } catch (\Throwable) {
            // A leftover temp file is harmless; never fail a send over cleanup.
        }
    }

    /**
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getDirectory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
