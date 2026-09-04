<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Controller\Seller;

use Ahy\PlpRevamp\Service\Seller\NameResolver;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * AJAX endpoint: real marketplace seller per product.
 *
 * GET ahy_plprevamp/seller/names?ids=123,456
 *   -> {"names": {"123": {"seller_id": 9878, "name": "The Everest Marketplace"}}}
 *
 * Products with no resolvable seller are omitted, so the cards can render an
 * empty seller line instead of a hardcoded placeholder.
 */
class Names implements HttpGetActionInterface
{
    /** Matches the CaliberNation price endpoint's per-request cap. */
    private const MAX_IDS = 50;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly NameResolver $resolver
    ) {}

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHeader('Cache-Control', 'public, max-age=600', true);

        $raw = (string) $this->request->getParam('ids', '');
        $ids = array_values(
            array_filter(
                array_unique(array_map('intval', explode(',', $raw))),
                static fn(int $id) => $id > 0
            )
        );
        $ids = array_slice($ids, 0, self::MAX_IDS);

        if (!$ids) {
            return $result->setData(['names' => []]);
        }

        return $result->setData(['names' => $this->resolver->resolve($ids)]);
    }
}
