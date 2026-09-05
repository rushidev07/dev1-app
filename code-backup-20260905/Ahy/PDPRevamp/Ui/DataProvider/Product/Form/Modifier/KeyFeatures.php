<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier;

use Magento\Ui\DataProvider\Modifier\ModifierInterface;

/**
 * Replaces the auto-generated field for the "key_features" attribute
 * (see Setup/Patch/Data/CreateKeyFeaturesAttribute.php) with a dynamicRows
 * editor - unlimited title+description rows, add/remove per row - instead
 * of a plain textarea. The attribute itself stores the rows as a single
 * JSON string; Model/Product/Attribute/Backend/KeyFeatures.php converts
 * transparently between that string and the array this editor works with,
 * so load/save go through Magento's normal product save with no custom
 * table or controller plugin. Rendered on the storefront as the PDP
 * "Key Features" tile grid (product/view/key-features.phtml), which falls
 * back to parsing the product description's bullet list when this
 * attribute is empty.
 *
 * Also adds a sibling hidden "key_features_marker" field alongside the
 * grid. Deleting the last row leaves nothing for the browser to submit for
 * product[key_features] at all - bracket-notation form params can't
 * represent "explicitly emptied array", so an absent key is indistinguishable
 * from "this section wasn't part of the request". The marker is a plain
 * static field (not a row), so it keeps submitting even at zero rows; see
 * Plugin/Catalog/Controller/Adminhtml/Product/Initialization/Helper/
 * ClearEmptyKeyFeatures.php, which uses it to tell the two cases apart.
 */
class KeyFeatures implements ModifierInterface
{
    private const ATTRIBUTE_CODE = 'key_features';
    private const MARKER_CODE = 'key_features_marker';

    public function modifyData(array $data): array
    {
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        return $this->overrideKeyFeaturesField($meta);
    }

    private function overrideKeyFeaturesField(array $meta): array
    {
        foreach ($meta as $key => $node) {
            if (isset($node['children'][self::ATTRIBUTE_CODE])) {
                $meta[$key]['children'][self::ATTRIBUTE_CODE] = $this->buildDynamicRowsConfig();
                $meta[$key]['children'][self::MARKER_CODE] = $this->buildMarkerConfig();
                continue;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $meta[$key]['children'] = $this->overrideKeyFeaturesField($node['children']);
            }
        }

        return $meta;
    }

    private function buildMarkerConfig(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'field',
                        'formElement' => 'input',
                        'dataType' => 'text',
                        'visible' => false,
                        'value' => '1',
                        'sortOrder' => 5,
                    ],
                ],
            ],
        ];
    }

    private function buildDynamicRowsConfig(): array
    {
        return [
            'arguments' => [
                'data' => [
                    'config' => [
                        'componentType' => 'dynamicRows',
                        'component' => 'Magento_Ui/js/dynamic-rows/dynamic-rows',
                        'label' => __('Key Features'),
                        'columnsHeader' => true,
                        'renderDefaultRecord' => false,
                        'recordTemplate' => 'record',
                        'addButtonLabel' => __('Add Feature'),
                        'dataScope' => '',
                        'dndConfig' => [
                            'enabled' => true,
                        ],
                        'map' => [
                            'title' => 'title',
                            'description' => 'description',
                        ],
                        'sortOrder' => 10,
                    ],
                ],
            ],
            'children' => [
                'record' => [
                    'arguments' => [
                        'data' => [
                            'config' => [
                                'componentType' => 'container',
                                'isTemplate' => true,
                                'is_collection' => true,
                                'component' => 'Magento_Ui/js/dynamic-rows/record',
                            ],
                        ],
                    ],
                    'children' => [
                        'title' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'field',
                                        'formElement' => 'input',
                                        'dataType' => 'text',
                                        'label' => __('Title'),
                                        'dataScope' => 'title',
                                        'sortOrder' => 10,
                                    ],
                                ],
                            ],
                        ],
                        'description' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'field',
                                        'formElement' => 'textarea',
                                        'dataType' => 'text',
                                        'label' => __('Description'),
                                        'dataScope' => 'description',
                                        'sortOrder' => 20,
                                    ],
                                ],
                            ],
                        ],
                        'actionDelete' => [
                            'arguments' => [
                                'data' => [
                                    'config' => [
                                        'componentType' => 'actionDelete',
                                        'dataType' => 'text',
                                        'label' => '',
                                        'sortOrder' => 30,
                                        'fit' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
