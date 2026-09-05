<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Service\FbtSuggestionProvider;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Helper\Data as PricingHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

class FrequentlyBoughtTogether extends Template
{
    private const XML_PATH_ENABLED = 'pdprevamp_fbt/general/enabled';
    private const XML_PATH_HEADING = 'pdprevamp_fbt/general/heading';
    private const XML_PATH_DISCOUNT_PERCENT = 'pdprevamp_fbt/general/discount_percent';

    private ViewModelRegistry $viewModelRegistry;
    private ImageHelper $imageHelper;
    private PricingHelper $priceHelper;
    private FbtSuggestionProvider $suggestionProvider;

    /** @var Product[]|null */
    private ?array $suggestions = null;

    public function __construct(
        Context $context,
        ViewModelRegistry $viewModelRegistry,
        ImageHelper $imageHelper,
        PricingHelper $priceHelper,
        FbtSuggestionProvider $suggestionProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->viewModelRegistry = $viewModelRegistry;
        $this->imageHelper = $imageHelper;
        $this->priceHelper = $priceHelper;
        $this->suggestionProvider = $suggestionProvider;
    }

    public function getCurrentProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    public function getImageHelper(): ImageHelper
    {
        return $this->imageHelper;
    }

    public function getPriceHelper(): PricingHelper
    {
        return $this->priceHelper;
    }

    /**
     * Whether the section should render at all.
     *
     * Requires the master switch AND at least one suggestion. A section headed
     * "Frequently Bought Together" with nothing beside the current product is
     * worse than no section, so the template checks this before emitting anything.
     */
    public function isEnabled(): bool
    {
        if (!$this->_scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return false;
        }

        return $this->getSuggestedProducts() !== [];
    }

    public function getHeading(): string
    {
        $heading = trim((string) $this->_scopeConfig->getValue(
            self::XML_PATH_HEADING,
            ScopeInterface::SCOPE_STORE
        ));

        return $heading !== '' ? $heading : (string) __('Frequently Bought Together');
    }

    /**
     * @return Product[]
     */
    public function getSuggestedProducts(): array
    {
        // Memoised: the template asks for these several times per render (count,
        // the cards, the bundle total), and the provider runs an aggregate query
        // over sales_order_item.
        if ($this->suggestions === null) {
            $this->suggestions = $this->suggestionProvider->getSuggestions($this->getCurrentProduct());
        }

        return $this->suggestions;
    }

    /**
     * All bundle items: the current product first, then suggestions.
     *
     * @return Product[]
     */
    public function getBundleItems(): array
    {
        return array_merge([$this->getCurrentProduct()], $this->getSuggestedProducts());
    }

    public function getBundleDiscountPercent(): int
    {
        $percent = (int) $this->_scopeConfig->getValue(
            self::XML_PATH_DISCOUNT_PERCENT,
            ScopeInterface::SCOPE_STORE
        );

        if ($percent < 0) {
            return 0;
        }

        return $percent > 100 ? 100 : $percent;
    }

    public function getItemFinalPrice(Product $item): float
    {
        return (float) $item->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
    }

    public function getItemRegularPrice(Product $item): float
    {
        return (float) $item->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
    }
}
