<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Ui\Component\Listing\Column;

use Ahy\CaliberNation\Model\RefundRequest;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders status as a clickable button that toggles between Pending and Reviewed.
 */
class RefundRequestStatus extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly FormKey $formKey,
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
            $status = $item[$name] ?? RefundRequest::STATUS_PENDING;
            $id     = (int) ($item['entity_id'] ?? 0);
            $url    = $this->urlBuilder->getUrl('calibernation/refundrequest/review', ['id' => $id]);

            if ($status === RefundRequest::STATUS_REINSTATED) {
                // If reviewed_by is set the row was Completed before becoming Rejoined —
                // the refund was already given. Show a distinct badge so admins can tell.
                if (!empty($item['reviewed_by'])) {
                    $item[$name] = '<span style="background:#e67e22;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;display:inline-block;">Refund + Rejoined</span>';
                } else {
                    $item[$name] = '<span style="background:#007bff;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;display:inline-block;">Rejoined</span>';
                }
            } elseif ($status === RefundRequest::STATUS_REVIEWED) {
                $item[$name] = '<form method="post" action="' . $url . '" style="display:inline;">'
                    . '<input type="hidden" name="form_key" value="' . $this->getFormKey() . '"/>'
                    . '<button type="submit" style="background:#28a745;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;border:none;cursor:pointer;">Completed</button>'
                    . '</form>';
            } else {
                $item[$name] = '<form method="post" action="' . $url . '" style="display:inline;">'
                    . '<input type="hidden" name="form_key" value="' . $this->getFormKey() . '"/>'
                    . '<button type="submit" style="background:#fd7e14;color:#fff;padding:4px 12px;border-radius:4px;font-size:12px;font-weight:600;border:none;cursor:pointer;">Pending</button>'
                    . '</form>';
            }
        }

        return $dataSource;
    }

    private function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
