<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Service\Email;

use Ahy\DiscountAlert\Api\ProductCollectorInterface as Collector;
use Ahy\DiscountAlert\Block\Email\BracketSummary;
use Ahy\DiscountAlert\Block\Email\ProductsTable;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\View\LayoutInterface;

/**
 * Renders the email's tabular sections through blocks and .phtml templates, so the
 * markup lives in the view layer and stays editable by a front-end developer instead of
 * being concatenated inside a service class.
 *
 * The templates live under view/base so they resolve from cron, CLI and admin alike.
 *
 * The layout is built through a factory rather than injected: this service sits in the
 * dependency graph of a console command, and constructing the view layer while no area is
 * set fails with "Area code is not set". Creating it lazily inside the area emulation
 * keeps CLI bootstrap free of view-layer objects and gives the layout the right area.
 */
class AlertContentRenderer
{
    private ?LayoutInterface $layout = null;

    public function __construct(
        private readonly LayoutFactory $layoutFactory,
        private readonly State $appState
    ) {}

    /**
     * @param array<int, array<string, mixed>> $products
     */
    public function renderBracketSummary(array $products, float $threshold): string
    {
        $percentages = \array_map(
            static fn (array $product): float => (float) ($product[Collector::KEY_DISCOUNT_PCT] ?? 0),
            $products
        );

        return $this->renderInAdminArea(
            fn (): string => $this->getLayout()->createBlock(BracketSummary::class)
                ->setData('percentages', $percentages)
                ->setData('threshold', $threshold)
                ->toHtml()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $products
     */
    public function renderProductsTable(array $products): string
    {
        return $this->renderInAdminArea(
            fn (): string => $this->getLayout()->createBlock(ProductsTable::class)
                ->setData('products', $products)
                ->toHtml()
        );
    }

    /**
     * Cron and CLI run outside any request area; template resolution needs one.
     */
    private function renderInAdminArea(callable $callback): string
    {
        return (string) $this->appState->emulateAreaCode(Area::AREA_ADMINHTML, $callback);
    }

    private function getLayout(): LayoutInterface
    {
        if ($this->layout === null) {
            $this->layout = $this->layoutFactory->create();
        }

        return $this->layout;
    }
}
