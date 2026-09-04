<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Setup\Patch\Data\CreatePdpSpecificationsAttribute;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * PDP "Specifications" tab (see product/view/specifications.phtml). Reads
 * the pdp_specifications textarea attribute and parses it into label/value
 * pairs, one per line, splitting each line on its first colon:
 *
 *   Volume: 20 Liters
 *   Weight: 1.1 lbs (0.5 kg)
 *
 * A line with no colon is skipped rather than guessed at, so a typo doesn't
 * silently produce a value-less row.
 */
class Specifications extends Template
{
    private ViewModelRegistry $viewModelRegistry;

    public function __construct(
        Context $context,
        ViewModelRegistry $viewModelRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->viewModelRegistry = $viewModelRegistry;
    }

    public function getProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getSpecifications(): array
    {
        $raw = (string) $this->getProduct()->getData(CreatePdpSpecificationsAttribute::ATTRIBUTE_CODE);
        if (trim($raw) === '') {
            return [];
        }

        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$label, $value] = explode(':', $line, 2);
            $label = trim($label);
            $value = trim($value);
            if ($label === '' || $value === '') {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }
}
