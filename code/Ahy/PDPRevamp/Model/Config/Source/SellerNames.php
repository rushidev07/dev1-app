<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Webkul\Marketplace\Model\ResourceModel\Seller\CollectionFactory as MpSellerCollectionFactory;

/**
 * Every real Webkul Marketplace seller's display name, for the "Best
 * Seller - Seller Name" config dropdown (Stores > Configuration > AHY >
 * PDP Badges). The stored value is still just the plain display name
 * string (shop_title, falling back to name) - matching how
 * AdventureSeekersAlsoViewed/CustomersAlsoBought already resolve this
 * field by comparing text, not a seller_id - so this only replaces free
 * typing with a guaranteed-to-match list, no matching logic changes.
 */
class SellerNames implements ArrayInterface
{
    private MpSellerCollectionFactory $mpSellerCollectionFactory;

    public function __construct(MpSellerCollectionFactory $mpSellerCollectionFactory)
    {
        $this->mpSellerCollectionFactory = $mpSellerCollectionFactory;
    }

    public function toOptionArray(): array
    {
        $collection = $this->mpSellerCollectionFactory->create();

        $names = [];
        foreach ($collection as $seller) {
            $shopTitle = trim((string) $seller->getData('shop_title'));
            $name = trim((string) $seller->getData('name'));
            $display = $shopTitle !== '' ? $shopTitle : $name;
            if ($display !== '') {
                $names[$display] = $display;
            }
        }

        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $options = [['value' => '', 'label' => __('-- None (use default behavior) --')]];
        foreach ($names as $displayName) {
            $options[] = ['value' => $displayName, 'label' => $displayName];
        }

        return $options;
    }
}
