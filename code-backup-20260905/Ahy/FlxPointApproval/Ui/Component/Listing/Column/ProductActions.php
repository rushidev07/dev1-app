<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ProductActions extends Column
{
    private UrlInterface $urlBuilder;

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $entityId = $item['entity_id'];

            // Variant rows use offset IDs > 10M — no actions for them
            if ($entityId > 10000000) {
                $item[$this->getData('name')] = [];
                continue;
            }

            $status = $item['status'] ?? 'pending';

            $item[$this->getData('name')] = [];

            $item[$this->getData('name')]['view'] = [
                'href'  => $this->urlBuilder->getUrl(
                    'ahy_flxpoint_approval/product/view',
                    ['entity_id' => $entityId]
                ),
                'label' => __('View'),
            ];

            if ($status === 'pending') {
                $item[$this->getData('name')]['approve'] = [
                    'href'    => $this->urlBuilder->getUrl(
                        'ahy_flxpoint_approval/product/approve',
                        ['entity_id' => $entityId]
                    ),
                    'label'   => __('Approve'),
                    'confirm' => [
                        'title'   => __('Approve Product'),
                        'message' => __('Are you sure you want to approve and import this product?'),
                    ],
                ];

                $item[$this->getData('name')]['reject'] = [
                    'href'  => $this->urlBuilder->getUrl(
                        'ahy_flxpoint_approval/product/rejectForm',
                        ['entity_id' => $entityId]
                    ),
                    'label' => __('Reject'),
                ];
            }

            if ($status === 'approved' && !empty($item['import_error'])) {
                $item[$this->getData('name')]['retry'] = [
                    'href'    => $this->urlBuilder->getUrl(
                        'ahy_flxpoint_approval/product/approve',
                        ['entity_id' => $entityId]
                    ),
                    'label'   => __('Retry Import'),
                    'confirm' => [
                        'title'   => __('Retry Import'),
                        'message' => __('Retry importing this product into Magento?'),
                    ],
                ];
            }
        }

        return $dataSource;
    }
}