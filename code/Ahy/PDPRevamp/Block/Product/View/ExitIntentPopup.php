<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateExitPopupEnabledAttribute;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class ExitIntentPopup extends Template
{
    private const XML_PATH_ENABLED = 'pdprevamp_exit_popup/general/enabled';
    private const XML_PATH_TIMER_HOURS = 'pdprevamp_exit_popup/general/timer_hours';
    private const XML_PATH_DISCOUNT_PERCENT = 'pdprevamp_exit_popup/general/discount_percent';
    private const XML_PATH_DESCRIPTION_TEXT = 'pdprevamp_exit_popup/general/description_text';

    private ViewModelRegistry $viewModelRegistry;

    public function __construct(
        Context $context,
        ViewModelRegistry $viewModelRegistry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->viewModelRegistry = $viewModelRegistry;
    }

    /**
     * Both the store-wide switch and this product's own opt-in must be on.
     *
     * The popup used to render on every PDP - the global switch defaults to 1 in
     * etc/config.xml and was the only gate. The per-product attribute is what
     * scopes it; the global setting is kept as a store-wide kill switch so the
     * feature can be turned off without editing products.
     */
    public function isEnabled(): bool
    {
        $globallyOn = $this->_scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return $globallyOn && $this->isEnabledForCurrentProduct();
    }

    /**
     * Also requires the product to be salable - a code should not be issued
     * against something that cannot be bought.
     */
    private function isEnabledForCurrentProduct(): bool
    {
        $product = $this->getCurrentProduct();
        if ($product === null || !$product->getId()) {
            return false;
        }

        if (!(bool) $product->getData(CreateExitPopupEnabledAttribute::ATTRIBUTE_CODE)) {
            return false;
        }

        try {
            return (bool) $product->isSalable();
        } catch (\Throwable $e) {
            // A type that cannot answer isSalable() should not block the popup.
            return true;
        }
    }

    public function getCurrentProductId(): int
    {
        $product = $this->getCurrentProduct();

        return $product ? (int) $product->getId() : 0;
    }

    private function getCurrentProduct(): ?Product
    {
        try {
            /** @var CurrentProduct $currentProduct */
            $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);

            return $currentProduct->get();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getTimerHours(): int
    {
        $hours = (int) $this->_scopeConfig->getValue(
            self::XML_PATH_TIMER_HOURS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return $hours > 0 ? $hours : 48;
    }

    public function getSubscribeUrl(): string
    {
        return $this->getUrl('ahyyotpo/index/subscribe');
    }

    public function getDiscountPercent(): int
    {
        $percent = (int) $this->_scopeConfig->getValue(
            self::XML_PATH_DISCOUNT_PERCENT,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        return $percent > 0 ? $percent : 10;
    }

    public function getDescriptionText(): string
    {
        $text = trim((string) $this->_scopeConfig->getValue(
            self::XML_PATH_DESCRIPTION_TEXT,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));

        return $text !== ''
            ? $text
            : "your first order. Enter your email below and we'll send your exclusive discount code.";
    }
}
