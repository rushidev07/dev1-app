<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Block\Adminhtml\SellerPricing\Edit;

use Magento\Backend\Block\Widget\Context;

class GenericButton
{
    public function __construct(
        protected readonly Context $context
    ) {}

    public function getEntityId(): ?int
    {
        $id = (int) $this->context->getRequest()->getParam('entity_id');
        return $id ?: null;
    }

    public function getUrl(string $route = '', array $params = []): string
    {
        return $this->context->getUrlBuilder()->getUrl($route, $params);
    }
}
