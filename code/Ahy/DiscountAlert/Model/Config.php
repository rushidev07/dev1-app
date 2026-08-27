<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model;

use Ahy\DiscountAlert\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Config implements ConfigInterface
{
    private const XML_PATH_ENABLED             = 'ahy_discount_alert/general/enabled';
    private const XML_PATH_THRESHOLD           = 'ahy_discount_alert/general/threshold';
    private const XML_PATH_RECIPIENT_EMAIL     = 'ahy_discount_alert/general/recipient_email';
    private const XML_PATH_CC_EMAILS           = 'ahy_discount_alert/general/cc_emails';
    private const XML_PATH_BCC_EMAILS          = 'ahy_discount_alert/general/bcc_emails';
    private const XML_PATH_CHANGE_ONLY        = 'ahy_discount_alert/general/notify_on_change_only';
    private const XML_PATH_MAX_PRODUCTS        = 'ahy_discount_alert/email/max_products';
    private const XML_PATH_EMAIL_PRODUCT_LIMIT = 'ahy_discount_alert/email/product_limit';
    private const XML_PATH_LINK_LIFETIME       = 'ahy_discount_alert/security/link_lifetime';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
    }

    public function getThreshold(): float
    {
        return (float) $this->getValue(self::XML_PATH_THRESHOLD);
    }

    public function getRecipientEmail(): string
    {
        return \trim((string) $this->getValue(self::XML_PATH_RECIPIENT_EMAIL));
    }

    public function getCcEmails(): array
    {
        return $this->parseEmails((string) $this->getValue(self::XML_PATH_CC_EMAILS));
    }

    public function getBccEmails(): array
    {
        return $this->parseEmails((string) $this->getValue(self::XML_PATH_BCC_EMAILS));
    }

    public function isNotifyOnChangeOnly(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CHANGE_ONLY,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
    }

    public function getMaxProducts(): int
    {
        return \max(0, (int) $this->getValue(self::XML_PATH_MAX_PRODUCTS));
    }

    public function getEmailProductLimit(): int
    {
        return \max(0, (int) $this->getValue(self::XML_PATH_EMAIL_PRODUCT_LIMIT));
    }

    public function getLinkLifetimeDays(): int
    {
        return \max(0, (int) $this->getValue(self::XML_PATH_LINK_LIFETIME));
    }

    private function getValue(string $path): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    /**
     * Split a comma/semicolon/whitespace separated list into unique, valid emails.
     *
     * @return string[]
     */
    private function parseEmails(string $raw): array
    {
        if (\trim($raw) === '') {
            return [];
        }

        $parts = \preg_split('/[,;\s]+/', \trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = \array_filter(
            \array_map('trim', $parts),
            static fn (string $email): bool => (bool) \filter_var($email, FILTER_VALIDATE_EMAIL)
        );

        return \array_values(\array_unique($valid));
    }
}
