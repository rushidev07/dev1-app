<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service\Pricing;

use Ahy\CaliberNation\Model\MemberPriceRule;
use Ahy\CaliberNation\Model\ResourceModel\MemberPriceRule\CollectionFactory as RuleCollectionFactory;
use Ahy\CaliberNation\Model\ResourceModel\SellerParticipation\CollectionFactory as SellerCollectionFactory;

/**
 * Loads all active member-pricing rules ONCE per request and serves them from
 * memory — so resolving prices across a whole product listing costs one pair of
 * queries, not N. Membership base + global cap are config, not rules (see Config).
 */
class RuleProvider
{
    /** @var array<int,array{type:string,value:float}>|null seller_id => discount */
    private ?array $sellerRules = null;

    /** @var array<int,array{type:string,value:float}>|null category target_id => discount */
    private ?array $categoryRules = null;

    /** @var array<int,array{type:string,value:float}>|null product target_id => discount */
    private ?array $productRules = null;

    public function __construct(
        private readonly SellerCollectionFactory $sellerCollectionFactory,
        private readonly RuleCollectionFactory $ruleCollectionFactory
    ) {}

    /** @return array{type:string,value:float}|null */
    public function getSellerDiscount(?int $sellerId): ?array
    {
        if (!$sellerId) {
            return null;
        }
        $this->loadSellerRules();
        return $this->sellerRules[$sellerId] ?? null;
    }

    /**
     * All category-rule discounts matching any of the product's category ids.
     * @param int[] $categoryIds
     * @return array<int,array{type:string,value:float,target_id:int}>
     */
    public function getCategoryDiscounts(array $categoryIds): array
    {
        $this->loadRules();
        $out = [];
        foreach ($categoryIds as $catId) {
            $catId = (int) $catId;
            if (isset($this->categoryRules[$catId])) {
                $out[] = $this->categoryRules[$catId] + ['target_id' => $catId];
            }
        }
        return $out;
    }

    /** @return array{type:string,value:float}|null */
    public function getProductDiscount(?int $productId): ?array
    {
        if (!$productId) {
            return null;
        }
        $this->loadRules();
        return $this->productRules[$productId] ?? null;
    }

    private function loadSellerRules(): void
    {
        if ($this->sellerRules !== null) {
            return;
        }
        $this->sellerRules = [];
        $collection = $this->sellerCollectionFactory->create();
        $collection->addFieldToFilter('is_enabled', 1);
        foreach ($collection as $row) {
            $this->sellerRules[(int) $row->getData('seller_id')] = [
                'type'  => (string) $row->getData('discount_type'),
                'value' => (float) $row->getData('discount_value'),
            ];
        }
    }

    private function loadRules(): void
    {
        if ($this->categoryRules !== null) {
            return;
        }
        $this->categoryRules = [];
        $this->productRules = [];
        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        foreach ($collection as $rule) {
            $value = (float) $rule->getData('discount_value');
            if ($value <= 0) {
                continue;
            }
            $entry = [
                'type'  => (string) $rule->getData('discount_type'),
                'value' => $value,
            ];
            $targetId = (int) $rule->getData('target_id');
            if ($rule->getData('scope') === MemberPriceRule::SCOPE_CATEGORY) {
                $this->categoryRules[$targetId] = $entry;
            } else {
                $this->productRules[$targetId] = $entry;
            }
        }
    }
}
