<?php

namespace Ahy\EstateApiIntegration\Model;

use Ahy\EstateApiIntegration\Api\ProductZipValidatorInterface;
use Ahy\EstateApiIntegration\Service\EstateApiService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\DeploymentConfig;
use Ahy\EstateApiIntegration\Logger\Logger;

class ProductZipValidator implements ProductZipValidatorInterface
{

    private ProductRepositoryInterface $productRepository;
    private Logger $logger;
    private EstateApiService $estateApiService;
    private DeploymentConfig $deploymentConfig;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        Logger $logger,
        EstateApiService $estateApiService,
        DeploymentConfig $deploymentConfig
        
    ) {
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->estateApiService = $estateApiService;
        $this->deploymentConfig = $deploymentConfig;
      
    }

    /**
     * Validate a product's UPC against a ZIP code.
     *
     * @param int $productId
     * @param string $zip
     * @return int|array
     */
    public function validate(int $productId, string $zip)
    {
        try {
            $product = $this->productRepository->getById($productId);

            // Support upc / upc_number attributes
            $upcAttr = $product->getCustomAttribute('upc') 
                ?? $product->getCustomAttribute('upc_number');

            $upc = $upcAttr ? $upcAttr->getValue() : null;
            $this->logger->info("Validating product ID {$productId} with UPC {$upc} for ZIP {$zip}");

            if (!$upc) {
                $this->logger->warning("Missing UPC for product ID {$productId}");
                return 4;
            }

            // When the v1 endpoint is enabled, route this single-product validation through
            // the v1 batch service (one UPC) and convert the result back to the EXACT shape the
            // legacy path returns, so the frontend (submitZip) behaves identically:
            //   int                      → numeric code (firearms/mag/parts)
            //   ['orchid' => [...] ]      → ammunition object (wrapped, as legacy did)
            //   4                         → error / not found
        
            if ($this->isV1Enabled()) {
                $map = $this->estateApiService->validateProductUpcsWithZip([$upc], $zip);
                $result = $map[trim((string) $upc)] ?? 4;

                return is_array($result) ? ['orchid' => $result] : $result;
            }

            // Legacy single-UPC endpoint (used when orchid_api/use_v1 is off).
            return $this->estateApiService->validateProductUpcWithZip($upc, $zip);

        } catch (\Exception $e) {
            $this->logger->error("Error in validate(): " . $e->getMessage());
            return 4;
        }
    }
                 
    /**
     * Whether the Orchid v1 endpoint is enabled (env.php: orchid_api/use_v1).
     */
    private function isV1Enabled(): bool
    {
        try {
            return (bool) $this->deploymentConfig->get('orchid_api/use_v1');
        } catch (\Exception $e) {
            return false;
        }
    }
}
