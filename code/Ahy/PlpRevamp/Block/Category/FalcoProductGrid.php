<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Block\Category;

use FalcoSense\Search\Block\Category as FalcoCategory;
use FalcoSense\Search\Helper\Data as SmartSearchHelper;
use FalcoSense\Search\Service\SearchTokenService;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\View\Element\Template\Context;

class FalcoProductGrid extends FalcoCategory
{
    public function __construct(
        Context            $context,
        SmartSearchHelper  $helper,
        LayerResolver      $layerResolver,
        SearchTokenService $tokenService,
        array              $data = []
    ) {
        parent::__construct($context, $helper, $layerResolver, $tokenService, $data);
    }

    public function getAddToCartUrl(): string
    {
        return $this->getUrl('checkout/cart/add');
    }

    public function getYotpoEndpointUrl(): string
    {
        return $this->getUrl('ahy_plprevamp/yotpo/bottomline');
    }

    public function getPageSize(): int
    {
        return 24;
    }
}
