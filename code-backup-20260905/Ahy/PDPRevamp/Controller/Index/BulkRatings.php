<?php

namespace Ahy\PDPRevamp\Controller\Index;

use Ahy\PDPRevamp\Service\YotpoClient;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

class BulkRatings implements HttpGetActionInterface
{
    /** @var Context */
    private $context;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var YotpoClient */
    private $yotpoClient;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        YotpoClient $yotpoClient
    ) {
        $this->context = $context;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->yotpoClient = $yotpoClient;
    }

    public function execute(): ResultInterface
    {
        $request = $this->context->getRequest();
        $productId = (int) $request->getParam('product_id');

        $result = $this->resultJsonFactory->create();

        if (!$productId) {
            return $result->setHttpResponseCode(400)->setData(['error' => 'product_id is required']);
        }

        return $result->setData($this->yotpoClient->getBottomline($productId));
    }
}
