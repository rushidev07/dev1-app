<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Block\Adminhtml\SellerPricing\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        if (!$this->getEntityId()) {
            return [];
        }
        return [
            'label'      => __('Delete'),
            'class'      => 'delete',
            'on_click'   => sprintf(
                "deleteConfirm('%s', '%s');",
                __('Are you sure you want to delete this record?'),
                $this->getUrl('*/*/delete', ['entity_id' => $this->getEntityId()])
            ),
            'sort_order' => 20,
        ];
    }
}
