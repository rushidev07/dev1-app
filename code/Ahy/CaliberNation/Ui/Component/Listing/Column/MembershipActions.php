<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Ui\Component\Listing\Column;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions for the membership grid: charge now, cancel, and manual status
 * overrides (set active / expired). Each is a confirming POST to an admin controller.
 */
class MembershipActions extends Column
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

            $item[$name]['cancel'] = [
                'href'    => $this->urlBuilder->getUrl('calibernation/membership/cancel', ['id' => $id]),
                'label'   => __('Cancel'),
                'confirm' => ['title' => __('Cancel membership'), 'message' => __('Cancel this membership and disable auto-renew?')],
            ];
            $item[$name]['set_active'] = [
                'href'  => $this->urlBuilder->getUrl('calibernation/membership/setStatus', ['id' => $id, 'status' => MembershipInterface::STATUS_ACTIVE]),
                'label' => __('Set Active'),
            ];
        }

        return $dataSource;
    }
}
