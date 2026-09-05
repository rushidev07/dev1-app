<?php
declare(strict_types=1);

namespace Ahy\FlxPointApproval\Block\Adminhtml\Product;

use Ahy\FlxPointApproval\Model\Product;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class View extends Template
{
    protected $_template = 'Ahy_FlxPointApproval::product/view.phtml';

    private ?Product $product = null;

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function setProduct(Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('ahy_flxpoint_approval/product/index');
    }
}
