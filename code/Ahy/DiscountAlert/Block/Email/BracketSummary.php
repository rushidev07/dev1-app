<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Block\Email;

use Ahy\DiscountAlert\Model\Discount\BracketProvider;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * Renders the "distribution by discount range" cells of the alert email.
 *
 * @method float[] getPercentages()
 * @method float getThreshold()
 */
class BracketSummary extends Template
{
    protected $_template = 'Ahy_DiscountAlert::email/brackets.phtml';

    public function __construct(
        Context $context,
        private readonly BracketProvider $bracketProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getBrackets(): array
    {
        return $this->bracketProvider->summarize(
            (array) $this->getData('percentages'),
            (float) $this->getData('threshold')
        );
    }

    /**
     * Column width as a percentage, so the summary table stays even for any number of
     * bands the configured threshold produces.
     */
    public function getColumnWidth(): string
    {
        $count = \max(1, \count($this->getBrackets()));

        return \rtrim(\rtrim(\number_format(100 / $count, 2, '.', ''), '0'), '.');
    }
}
