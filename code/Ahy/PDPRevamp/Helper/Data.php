<?php
namespace Ahy\PDPRevamp\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\MediaStorage\Service\ImageResize;

class Data extends AbstractHelper
{
    private const XML_PATH_GEMINI_API_KEY = 'pdprevamp_ai_descriptors/general/gemini_api_key';
    private const XML_PATH_GEMINI_MODEL = 'pdprevamp_ai_descriptors/general/gemini_model';

    private ImageResize $imageResizeService;
    private EncryptorInterface $encryptor;

    public function __construct(
        Context $context,
        ImageResize $imageResizeService,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($context);
        $this->imageResizeService = $imageResizeService;
        $this->encryptor = $encryptor;
    }

    public function getGeminiApiKey(): ?string
    {
        $encrypted = (string) $this->scopeConfig->getValue(self::XML_PATH_GEMINI_API_KEY);
        if ($encrypted === '') {
            return null;
        }
        $decrypted = $this->encryptor->decrypt($encrypted);
        return $decrypted !== '' ? $decrypted : null;
    }

    public function getGeminiModel(): string
    {
        $model = (string) $this->scopeConfig->getValue(self::XML_PATH_GEMINI_MODEL);
        return $model !== '' ? $model : 'gemini-2.5-flash';
    }

    public function getImageResizeService($image)
    {
        try {
            $this->imageResizeService->resizeFromImageName(originalImageName: $image->getFile());
        } catch (\Magento\Framework\Exception\NotFoundException $e) {
            if ($this->recoverMissingImage((string) $image->getFile())) {
                try {
                    $this->imageResizeService->resizeFromImageName(originalImageName: $image->getFile());
                    return;
                } catch (\Exception $retryError) {
                    $e = $retryError;
                }
            }
            $this->_logger->warning('Cannot resize product image: ' . $e->getMessage());
        }
    }

    /**
     * Local-dev only: fetch an original image missing from a partial media dump
     * from the production media host. No-op unless the store runs on localhost.
     */
    private function recoverMissingImage(string $file): bool
    {
        $baseUrl = (string) $this->scopeConfig->getValue('web/unsecure/base_url');
        if (strpos($baseUrl, 'localhost') === false) {
            return false;
        }

        $target = BP . '/pub/media/catalog/product' . $file;
        if (file_exists($target)) {
            return true;
        }

        $url = 'https://www.everest.com/media/catalog/product/'
            . implode('/', array_map('rawurlencode', explode('/', ltrim($file, '/'))));
        $context = stream_context_create([
            'http' => ['timeout' => 10, 'header' => "User-Agent: Mozilla/5.0\r\n"],
        ]);
        $data = @file_get_contents($url, false, $context);
        if ($data === false || $data === '') {
            return false;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }
        return @file_put_contents($target, $data) !== false;
    }
}
