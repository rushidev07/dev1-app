<?php
declare(strict_types=1);

namespace FalcoSense\Search\Observer;

use FalcoSense\Search\Helper\Data;
use FalcoSense\Search\Service\ProductSyncService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fires after a product is deleted directly through Magento (admin "Delete" action,
 * ProductRepository::delete()/deleteById(), or any path that ends in
 * AbstractModel::delete()). Closes the one gap none of the other FalcoSense
 * observers — or even the cron/full-sync disabled/OOS sweep — can cover: a
 * product removed from Magento's own database entirely. Before this observer
 * existed, the ONLY thing that ever caught this case was smartsearch:sync:full's
 * reconciliation pass, which only runs when that command is executed — so a
 * deleted product could stay fully live and purchasable-looking on the platform
 * indefinitely between runs (see FullSyncCommand::reconcileDeletedProducts()).
 *
 * Deliberately loops every store with a configured API key (same pattern as
 * Cron\ProductSync::getConfiguredStoreIds()) rather than trusting
 * $product->getStoreIds()/getWebsiteIds() — those read catalog_product_website,
 * whose rows may already be gone by the time this event fires
 * (AbstractDb::delete() removes related child rows via objectRelationProcessor
 * BEFORE dispatching catalog_product_delete_after), so they can silently return
 * empty and skip every store. A delete call to a store this product was never
 * actually synced under is a harmless no-op on the platform side (see
 * ProductIngestService::deleteProducts()).
 */
class ProductDeleteObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                  $helper,
        private readonly ProductSyncService    $syncService,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();

        if (!$product || !$product->getId()) {
            $this->logger->debug('[SmartSearch][RT] ProductDeleteObserver fired but product is null/no ID — skipping.');
            return;
        }

        $productId = (int) $product->getId();
        $sku       = (string) $product->getSku();

        foreach ($this->getConfiguredStoreIds() as $storeId) {
            if (!$this->helper->isEnabled($storeId)) {
                continue;
            }

            $this->logger->info(sprintf(
                '[SmartSearch][RT] ProductDeleteObserver fired: product_id=%d sku=%s store=%d — deleting from platform.',
                $productId,
                $sku,
                $storeId
            ));

            // Deliberately NOT gated behind isRealtimeSyncEnabled()'s "cron will catch
            // it" reasoning, and not rate-limited the way ProductSaveObserver is — a
            // missed delete leaves a fully live, purchasable-looking product on the
            // platform until the next full sync's reconciliation pass, a far worse
            // outcome than a missed save/price update (which the delta cron re-syncs
            // within a minute regardless).
            $result = $this->syncService->delete($productId, $storeId);

            $this->logger->info(sprintf(
                '[SmartSearch][RT] delete() returned %s for product %d (store %d).',
                $result ? 'true' : 'false',
                $productId,
                $storeId
            ));
        }
    }

    /**
     * All store IDs that resolve a non-empty API key — same definition
     * Cron\ProductSync::getConfiguredStoreIds() and FullSyncCommand use.
     *
     * @return int[]
     */
    private function getConfiguredStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            $sid = (int) $store->getId();
            if ($this->helper->getApiKey($sid)) {
                $ids[] = $sid;
            }
        }
        return $ids;
    }
}
