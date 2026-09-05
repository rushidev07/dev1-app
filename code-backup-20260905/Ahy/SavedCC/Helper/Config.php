<?php
declare(strict_types=1);

namespace Ahy\SavedCC\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_ACTIVE          = 'payment/ahy_savedcc/active';
    public const XML_PATH_VALIDATION_MODE = 'payment/ahy_savedcc/validation_mode';

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getValidationMode(?int $storeId = null): string
    {
        return (string) ($this->scopeConfig->getValue(
            self::XML_PATH_VALIDATION_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 'testMode');
    }
}
