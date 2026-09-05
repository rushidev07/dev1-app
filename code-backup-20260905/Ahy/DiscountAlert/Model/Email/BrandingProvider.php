<?php

declare(strict_types=1);

namespace Ahy\DiscountAlert\Model\Email;

use Magento\Config\Model\Config\Backend\Email\Logo as EmailLogoBackend;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Branding for the alert email, taken from existing store configuration so the module
 * carries no logo path, domain or company name of its own.
 */
class BrandingProvider
{
    private const XML_PATH_EMAIL_LOGO = 'design/email/logo';
    private const XML_PATH_STORE_NAME = 'general/store_information/name';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem
    ) {}

    /**
     * URL of the logo configured under Stores > Configuration > Design > Transactional
     * Emails. Empty when unset or missing on disk, so the template can fall back to text
     * instead of linking a broken image.
     */
    public function getLogoUrl(int $storeId): string
    {
        $fileName = (string) $this->scopeConfig->getValue(
            self::XML_PATH_EMAIL_LOGO,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($fileName === '') {
            return '';
        }

        $relativePath = EmailLogoBackend::UPLOAD_DIR . '/' . $fileName;

        try {
            if (!$this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->isFile($relativePath)) {
                return '';
            }

            $baseUrl = $this->storeManager->getStore($storeId)
                ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        } catch (\Throwable) {
            return '';
        }

        return \rtrim($baseUrl, '/') . '/' . $relativePath;
    }

    /**
     * Store Information name, falling back to the default store view's label.
     */
    public function getStoreName(int $storeId): string
    {
        $name = \trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_STORE_NAME,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        if ($name !== '') {
            return $name;
        }

        try {
            return (string) $this->storeManager->getDefaultStoreView()?->getName();
        } catch (\Throwable) {
            return '';
        }
    }
}
