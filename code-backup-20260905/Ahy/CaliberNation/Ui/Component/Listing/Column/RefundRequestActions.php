<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Ui\Component\Listing\Column;

use Ahy\CaliberNation\Model\RefundRequest;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions for the refund requests grid.
 * Shows "Review" only for pending requests.
 */
class RefundRequestActions extends Column
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

            if (($item['status'] ?? '') !== RefundRequest::STATUS_REVIEWED) {
                $item[$name]['review'] = [
                    'href'    => $this->urlBuilder->getUrl('calibernation/refundrequest/review', ['id' => (int) $item['entity_id']]),
                    'label'   => __('Review'),
                    'confirm' => [
                        'title'   => __('Mark as Reviewed'),
                        'message' => __('Mark this refund request as reviewed?'),
                    ],
                ];
            }
        }

        return $dataSource;
    }
}
