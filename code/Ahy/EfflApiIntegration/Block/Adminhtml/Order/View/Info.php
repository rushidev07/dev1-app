<?php
namespace Ahy\EfflApiIntegration\Block\Adminhtml\Order\View;

use Magento\Catalog\Api\ProductRepositoryInterface;

class Info extends \Magento\Sales\Block\Adminhtml\Order\View\Info
{
    private $productRepository;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Helper\Admin $adminHelper,
        \Magento\Customer\Api\GroupRepositoryInterface $groupRepository,
        \Magento\Customer\Api\CustomerMetadataInterface $metadata,
        \Magento\Customer\Model\Metadata\ElementFactory $elementFactory,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        ProductRepositoryInterface $productRepository,
        array $data = []
    ) {
        $this->productRepository = $productRepository;
        parent::__construct(
            $context,
            $registry,
            $adminHelper,
            $groupRepository,
            $metadata,
            $elementFactory,
            $addressRenderer,
            $data
        );
    }

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('Ahy_EfflApiIntegration::order/view/info.phtml');
    }

    public function getProductRepository(): ProductRepositoryInterface
    {
        return $this->productRepository;
    }
}