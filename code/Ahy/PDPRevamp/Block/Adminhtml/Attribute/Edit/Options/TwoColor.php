<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Adminhtml\Attribute\Edit\Options;

use Ahy\PDPRevamp\Model\ResourceModel\NativeColorSwatch;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\Registry;
use Magento\Swatches\Helper\Data as SwatchHelper;
use Magento\Swatches\Helper\Media as SwatchMediaHelper;

class TwoColor extends Template
{
    /**
     * @var string
     */
    protected $_template = 'Ahy_PDPRevamp::catalog/product/attribute/two_color.phtml';

    private Registry $coreRegistry;

    private NativeColorSwatch $nativeColorSwatch;

    private SwatchHelper $swatchHelper;

    private SwatchMediaHelper $swatchMediaHelper;

    public function __construct(
        Context $context,
        Registry $coreRegistry,
        NativeColorSwatch $nativeColorSwatch,
        SwatchHelper $swatchHelper,
        SwatchMediaHelper $swatchMediaHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->coreRegistry = $coreRegistry;
        $this->nativeColorSwatch = $nativeColorSwatch;
        $this->swatchHelper = $swatchHelper;
        $this->swatchMediaHelper = $swatchMediaHelper;
    }

    /**
     * Same upload endpoint the native "Manage Swatch" grid's own "Upload a
     * file" option posts to (Magento\Swatches\Controller\Adminhtml\Iframe\Show)
     * - it moves the file to permanent storage and returns its relative path
     * immediately, so nothing extra is needed on save for an uploaded image
     * half to become the option's swatch value.
     */
    public function getUploadUrl(): string
    {
        return $this->getUrl('swatches/iframe/show');
    }

    /**
     * Base URL uploaded swatch images are served from - prefix a stored
     * relative path (e.g. "/w/i/image.jpg") with this to get a full URL.
     */
    public function getSwatchMediaBaseUrl(): string
    {
        return $this->swatchMediaHelper->getSwatchMediaUrl();
    }

    /**
     * Whether a stored swatch half is an uploaded image's relative path
     * rather than a hex - same leading-character convention
     * determineSwatchType() (Magento_Swatches) itself uses.
     */
    public function isImagePath(string $value): bool
    {
        return $value !== '' && $value[0] === '/';
    }

    /**
     * Same registry key Magento\Catalog\Controller\Adminhtml\Product\Attribute\Edit
     * registers the currently-edited attribute under.
     */
    public function getAttribute(): ?Attribute
    {
        $attribute = $this->coreRegistry->registry('entity_attribute');

        return $attribute instanceof Attribute ? $attribute : null;
    }

    public function isVisualSwatchAttribute(): bool
    {
        $attribute = $this->getAttribute();

        return $attribute !== null && $this->swatchHelper->isVisualSwatch($attribute);
    }

    /**
     * @return array<int, array{option_id: int, label: string, first: string, second: string}>
     */
    public function getTwoColorRows(): array
    {
        $attribute = $this->getAttribute();
        if ($attribute === null || !$attribute->getId() || !$this->isVisualSwatchAttribute()) {
            return [];
        }

        return $this->nativeColorSwatch->getTwoColorOptionsForAttribute((int) $attribute->getId());
    }

    /**
     * Every store view except the admin/default scope (store_id 0) - same
     * set the native "Manage Swatch" grid lists a column for, via
     * getStoresSortedBySortOrder() on Magento\Eav\Block\Adminhtml\Attribute\
     * Edit\Options\Options.
     *
     * @return \Magento\Store\Api\Data\StoreInterface[]
     */
    public function getStoreViews(): array
    {
        return $this->_storeManager->getStores(false);
    }

    /**
     * Shown for every Visual Swatch attribute, same as the native "Manage
     * Swatch" grid - even with zero two-color rows yet, so the "Add Swatch"
     * control (see the template) is always reachable.
     */
    protected function _toHtml(): string
    {
        return $this->isVisualSwatchAttribute() ? parent::_toHtml() : '';
    }
}
