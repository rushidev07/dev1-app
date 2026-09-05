<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Block\Adminhtml\Run;

use Ahy\DiscountAlert\Model\ResourceModel\Run\Collection as RunCollection;
use Ahy\DiscountAlert\Model\ResourceModel\Run\CollectionFactory as RunCollectionFactory;
use Ahy\DiscountAlert\Model\Run;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * Lists recorded alert runs, newest first, each linking through to its review grid.
 */
class Listing extends Template
{
    public const PARAM_PAGE  = 'page';
    public const PARAM_LIMIT = 'limit';

    private const DEFAULT_LIMIT = 20;
    private const PAGE_SIZES    = [20, 50, 100];

    protected $_template = 'Ahy_DiscountAlert::run/list.phtml';

    private ?RunCollection $collection = null;

    public function __construct(
        Context $context,
        private readonly RunCollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return Run[]
     */
    public function getRuns(): array
    {
        return \array_values($this->getCollection()->getItems());
    }

    public function getTotalRuns(): int
    {
        return (int) $this->getCollection()->getSize();
    }

    public function getTotalPages(): int
    {
        return \max(1, (int) $this->getCollection()->getLastPageNumber());
    }

    public function getCurrentPage(): int
    {
        return \max(1, (int) $this->getRequest()->getParam(self::PARAM_PAGE, 1));
    }

    public function getPageSize(): int
    {
        $limit = (int) $this->getRequest()->getParam(self::PARAM_LIMIT, self::DEFAULT_LIMIT);

        return \in_array($limit, self::PAGE_SIZES, true) ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * @return int[]
     */
    public function getPageSizes(): array
    {
        return self::PAGE_SIZES;
    }

    public function formatThreshold(Run $run): string
    {
        return \rtrim(\rtrim(\number_format($run->getThreshold(), 2, '.', ''), '0'), '.');
    }

    /**
     * Products this run flagged for the first time, versus the run before it.
     */
    public function getNewCount(Run $run): int
    {
        return $run->getNewCount();
    }

    /**
     * Products the previous run flagged that this run no longer flags.
     */
    public function getRemovedCount(Run $run): int
    {
        return $run->getRemovedCount();
    }

    public function isEmailSent(Run $run): bool
    {
        return $run->isEmailSent();
    }

    public function getDisabledCount(Run $run): int
    {
        return (int) $run->getData(RunCollection::FIELD_DISABLED_COUNT);
    }

    /**
     * Review URL for a run. The token comes from the row, so the link satisfies the same
     * check the emailed link does.
     */
    public function getReviewUrl(Run $run): string
    {
        return $this->getUrl('*/*/view', [
            'run_id' => (int) $run->getRunId(),
            'token'  => $run->getToken(),
        ]);
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('*/*/index', [
            self::PARAM_PAGE  => $page,
            self::PARAM_LIMIT => $this->getPageSize(),
        ]);
    }

    public function getListUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    private function getCollection(): RunCollection
    {
        if ($this->collection === null) {
            /** @var RunCollection $collection */
            $collection = $this->collectionFactory->create();
            $collection->addDisabledCount()
                ->addNewestFirst()
                ->setPageSize($this->getPageSize())
                ->setCurPage($this->getCurrentPage());

            $this->collection = $collection;
        }

        return $this->collection;
    }
}
