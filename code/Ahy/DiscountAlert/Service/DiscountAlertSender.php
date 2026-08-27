<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service;

use Ahy\DiscountAlert\Api\ConfigInterface;
use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Ahy\DiscountAlert\Model\Email\BrandingProvider;
use Ahy\DiscountAlert\Service\Csv\DiscountCsvWriter;
use Ahy\DiscountAlert\Service\Email\AlertContentRenderer;
use Ahy\DiscountAlert\Service\Email\AlertMailer;
use Ahy\DiscountAlert\Service\Email\AttachmentFactory;
use Ahy\DiscountAlert\Service\Email\EnvelopeFactory;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;

/**
 * Assembles and sends the discount alert digest: renders the email sections, streams the
 * full list to a CSV, attaches it, and hands the message to the mailer.
 */
class DiscountAlertSender
{
    public const TEMPLATE_ID = 'ahy_discount_alert_email';

    private const CSV_MIME_TYPE = 'text/csv';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly AlertContentRenderer $contentRenderer,
        private readonly DiscountCsvWriter $csvWriter,
        private readonly AlertMailer $mailer,
        private readonly BrandingProvider $branding,
        private readonly TimezoneInterface $timezone,
        private readonly AttachmentFactory $attachmentFactory,
        private readonly EnvelopeFactory $envelopeFactory,
        private readonly Random $random
    ) {}

    /**
     * @param  array<int, array<string, mixed>> $products     Collected rows, deepest discount first.
     * @param  int                              $matchedTotal Total matches before the collection cap.
     * @param  int[]                            $newProductIds Product IDs flagged for the first time.
     * @param  string[]                         $ccEmails
     * @param  string[]                         $bccEmails
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function send(
        string $recipientEmail,
        array $products,
        float $threshold,
        int $matchedTotal,
        array $ccEmails = [],
        array $bccEmails = [],
        string $reviewUrl = '',
        array $newProductIds = []
    ): void {
        $storeId     = (int) Store::DEFAULT_STORE_ID;
        $displayName = \sprintf('discount_alert_%s.csv', $this->timezone->date()->format('Y-m-d'));

        // A unique working file keeps concurrent runs from overwriting each other; the
        // recipient still sees the friendly, dated name.
        $workingPath = $this->csvWriter->write(
            $products,
            $threshold,
            $this->random->getUniqueHash('discount_alert_') . '.csv'
        );

        try {
            $attachment = $this->attachmentFactory->create([
                'content'  => $this->csvWriter->read($workingPath),
                'fileName' => $displayName,
                'mimeType' => self::CSV_MIME_TYPE,
            ]);

            $this->mailer->send(
                $this->envelopeFactory->create([
                    'templateId'   => self::TEMPLATE_ID,
                    'templateVars' => $this->buildTemplateVars(
                        $products,
                        $threshold,
                        $matchedTotal,
                        $displayName,
                        $reviewUrl,
                        $storeId,
                        $newProductIds
                    ),
                    'to'          => [$recipientEmail],
                    'storeId'     => $storeId,
                    'cc'          => $ccEmails,
                    'bcc'         => $bccEmails,
                    'attachments' => [$attachment],
                ])
            );
        } finally {
            $this->csvWriter->delete($workingPath);
        }
    }

    /**
     * @param  array<int, array<string, mixed>> $products
     * @return array<string, mixed>
     */
    private function buildTemplateVars(
        array $products,
        float $threshold,
        int $matchedTotal,
        string $csvFileName,
        string $reviewUrl,
        int $storeId,
        array $newProductIds
    ): array {
        $bodyLimit   = $this->config->getEmailProductLimit();
        $storedCount = \count($products);

        // Split so the digest can lead with what actually needs attention. Both lists keep
        // the collector's ordering, deepest discount first.
        $newIndex = \array_flip(\array_map('intval', $newProductIds));
        $new      = [];
        $existing = [];

        foreach ($products as $product) {
            if (isset($newIndex[(int) $product[Collector::KEY_PRODUCT_ID]])) {
                $new[] = $product;
            } else {
                $existing[] = $product;
            }
        }

        $newShown      = $bodyLimit > 0 ? \array_slice($new, 0, $bodyLimit) : $new;
        $existingShown = $bodyLimit > 0 ? \array_slice($existing, 0, $bodyLimit) : $existing;

        return [
            'bracket_rows'            => $this->contentRenderer->renderBracketSummary($products, $threshold),
            'new_products_table'      => $this->contentRenderer->renderProductsTable($newShown),
            'existing_products_table' => $this->contentRenderer->renderProductsTable($existingShown),
            'new_total_count'         => \count($new),
            'new_shown_count'         => \count($newShown),
            'existing_total_count'    => \count($existing),
            'existing_shown_count'    => \count($existingShown),
            'total_count'    => $matchedTotal,
            'new_count'      => \count($new),
            'shown_count'    => \count($newShown) + \count($existingShown),
            'stored_count'   => $storedCount,
            'truncated'      => $storedCount < $matchedTotal ? '1' : '',
            'threshold'      => $threshold,
            'csv_filename'   => $csvFileName,
            'run_date'       => $this->timezone->date()->format('D, d M Y'),
            'review_url'     => $reviewUrl,
            'logo_url'       => $this->branding->getLogoUrl($storeId),
            'store_name'     => $this->branding->getStoreName($storeId),
        ];
    }
}
