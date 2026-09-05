<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Config\Model\ResourceModel\Config as ConfigWriter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * PayPal's Smart Button on the PDP defaults to a "pill" shape (fully
 * rounded), which looks inconsistent next to the Buy Now/EverestPay
 * buttons (rounded-lg). Forces "rect" to match.
 */
class SetPaypalButtonShapeRect implements DataPatchInterface
{
    private const CONFIG_PATH = 'paypal/style/product_page_button_shape';

    private ConfigWriter $configWriter;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ConfigWriter $configWriter,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
    }

    public function apply(): self
    {
        $current = $this->scopeConfig->getValue(self::CONFIG_PATH, ScopeInterface::SCOPE_STORE);
        if ($current !== 'rect') {
            $this->configWriter->saveConfig(self::CONFIG_PATH, 'rect');
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
