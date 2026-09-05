<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Controller\Adminhtml\KeywordsImport;

use Ahy\PlpRevamp\Model\Import\CardKeywordsImporter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

/**
 * Receives the CSV and hands it to the importer.
 *
 * POST-only: an import is a write, and a GET-able one would be re-runnable from
 * browser history.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Ahy_PlpRevamp::card_keywords_import';

    /** Detail lines echoed back to the admin before the rest are truncated. */
    private const MAX_MESSAGES = 25;

    public function __construct(
        Context $context,
        private readonly CardKeywordsImporter $importer
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('*/*/index');

        $file = $this->getRequest()->getFiles('import_file');
        if (!$file || empty($file['tmp_name']) || !empty($file['error'])) {
            $this->messageManager->addErrorMessage(__('Please choose a CSV file to upload.'));
            return $redirect;
        }

        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            $this->messageManager->addErrorMessage(__('Only .csv files are accepted.'));
            return $redirect;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->messageManager->addErrorMessage(__('The upload could not be verified. Please try again.'));
            return $redirect;
        }

        try {
            $result = $this->importer->import($file['tmp_name']);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Import failed: %1', $e->getMessage()));
            return $redirect;
        }

        // Per-column, because "5 updated" is ambiguous once a file can carry more
        // than one field.
        $parts = [];
        foreach ($result['columns'] as $column) {
            $parts[] = sprintf(
                '%s: %d updated, %d unchanged',
                $column,
                $result['updated'][$column] ?? 0,
                $result['unchanged'][$column] ?? 0
            );
        }
        if (!$parts) {
            $parts[] = (string) __('no content columns found');
        }

        $summary = __(
            'Import complete — %1. Skipped: %2. Failed: %3.',
            implode(' · ', $parts),
            $result['skipped'],
            $result['failed']
        );

        if ($result['failed'] > 0) {
            $this->messageManager->addErrorMessage($summary);
        } elseif ($result['skipped'] > 0) {
            $this->messageManager->addWarningMessage($summary);
        } else {
            $this->messageManager->addSuccessMessage($summary);
        }

        foreach (array_slice($result['messages'], 0, self::MAX_MESSAGES) as $message) {
            $this->messageManager->addNoticeMessage($message);
        }
        if (count($result['messages']) > self::MAX_MESSAGES) {
            $this->messageManager->addNoticeMessage(
                __('…and %1 more. Fix these and re-run to see the rest.', count($result['messages']) - self::MAX_MESSAGES)
            );
        }

        return $redirect;
    }
}
