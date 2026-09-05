<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit / delete row actions for the Member Price Rules grid.
 */
class PriceRuleActions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (empty($item['entity_id'])) {
                continue;
            }
            $id = (int) $item['entity_id'];
            $item[$name]['edit'] = [
                'href'  => $this->urlBuilder->getUrl('calibernation/pricerule/edit', ['entity_id' => $id]),
                'label' => __('Edit'),
            ];
            $item[$name]['delete'] = [
                'href'    => $this->urlBuilder->getUrl('calibernation/pricerule/delete', ['entity_id' => $id]),
                'label'   => __('Delete'),
                'confirm' => ['title' => __('Delete'), 'message' => __('Delete this member price rule?')],
            ];
        }
        return $dataSource;
    }
}
