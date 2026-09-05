<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualFbtLinkType;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Adds three more product-picker grids to the same "Related Products, Up-
 * Sells, and Cross-Sells" section Magento already ships, reusing its exact
 * button/modal/grid building blocks (see the inherited protected methods
 * on Related) - one for a manual "Customers Also Bought" override, one for
 * "Adventure Seekers Also Viewed". Both are used by
 * Block\Product\View\CustomersAlsoBought / AdventureSeekersAlsoViewed only
 * as a fallback when Amasty's automatic data has nothing for a product.
 *
 * The third grid feeds the "Frequently Bought Together" section, where the picks
 * are used when the co-purchase query finds nothing for this product - or always,
 * if pdp_fbt_force_manual is set on it.
 */
class ManualCarousels extends Related
{
    public const DATA_SCOPE_MANUAL_CAB = CreateManualCarouselLinkTypes::LINK_TYPE_CODE_MANUAL_CAB;
    public const DATA_SCOPE_MANUAL_ASAV = CreateManualCarouselLinkTypes::LINK_TYPE_CODE_MANUAL_ASAV;
    public const DATA_SCOPE_MANUAL_FBT = CreateManualFbtLinkType::LINK_TYPE_CODE_MANUAL_FBT;

    public function modifyMeta(array $meta)
    {
        $meta = parent::modifyMeta($meta);

        $meta[static::GROUP_RELATED]['children'][$this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB]
            = $this->getManualCabFieldset();
        $meta[static::GROUP_RELATED]['children'][$this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV]
            = $this->getManualAsavFieldset();
        $meta[static::GROUP_RELATED]['children'][$this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT]
            = $this->getManualFbtFieldset();

        return $meta;
    }

    protected function getDataScopes()
    {
        return array_merge(parent::getDataScopes(), [
            self::DATA_SCOPE_MANUAL_CAB,
            self::DATA_SCOPE_MANUAL_ASAV,
            self::DATA_SCOPE_MANUAL_FBT,
        ]);
    }

    protected function getManualCabFieldset(): array
    {
        $content = __(
            'Shown on this product\'s PDP "Customers Also Bought" section only when Amasty has no automatic '
            . 'suggestions yet for this product - it never overrides real Amasty data.'
        );

        return [
            'children' => [
                'button_set' => $this->getButtonSet(
                    $content,
                    __('Add Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB
                ),
                'modal' => $this->getGenericModal(
                    __('Add Customers Also Bought Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB
                ),
                self::DATA_SCOPE_MANUAL_CAB => $this->getGrid($this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB),
            ],
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Customers Also Bought (Manual Fallback)'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 40,
                    ],
                ],
            ],
        ];
    }

    protected function getManualAsavFieldset(): array
    {
        $content = __(
            'Shown on this product\'s PDP "Adventure Seekers Also Viewed" section only when Amasty has no '
            . 'automatic suggestions yet for this product - it never overrides real Amasty data.'
        );

        return [
            'children' => [
                'button_set' => $this->getButtonSet(
                    $content,
                    __('Add Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV
                ),
                'modal' => $this->getGenericModal(
                    __('Add Adventure Seekers Also Viewed Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV
                ),
                self::DATA_SCOPE_MANUAL_ASAV => $this->getGrid($this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV),
            ],
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Adventure Seekers Also Viewed (Manual Fallback)'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 50,
                    ],
                ],
            ],
        ];
    }

    protected function getManualFbtFieldset(): array
    {
        $content = __(
            'Shown in this product\'s PDP "Frequently Bought Together" section when no purchase history '
            . 'is available for it. Set "Always Use My FBT Picks" on this product to use these instead '
            . 'of purchase history. Drag to order - the section shows the first few. Only simple, '
            . 'in-stock, priced products can be added by the one-click bundle button.'
        );

        return [
            'children' => [
                'button_set' => $this->getButtonSet(
                    $content,
                    __('Add Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT
                ),
                'modal' => $this->getGenericModal(
                    __('Add Frequently Bought Together Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT
                ),
                self::DATA_SCOPE_MANUAL_FBT => $this->getGrid($this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT),
            ],
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Frequently Bought Together (Manual)'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 60,
                    ],
                ],
            ],
        ];
    }
}
