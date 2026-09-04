<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Amasty\Mostviewed\Api\Data\GroupInterface;
use Amasty\Mostviewed\Api\GroupRepositoryInterface;
use Amasty\Mostviewed\Model\OptionSource\BlockPosition;
use Amasty\Mostviewed\Model\OptionSource\SourceType;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class CreateCustomersAlsoBoughtGroup implements DataPatchInterface
{
    public const GROUP_NAME = 'Customers Also Bought';

    private GroupRepositoryInterface $groupRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private State $state;

    public function __construct(
        GroupRepositoryInterface $groupRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        State $state
    ) {
        $this->groupRepository = $groupRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->state = $state;
    }

    public function apply(): self
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            // Area code has already been set by the surrounding bootstrap; nothing to do.
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(GroupInterface::GROUP_NAME, self::GROUP_NAME)
            ->create();
        $existing = $this->groupRepository->getList($searchCriteria)->getItems();
        if (!empty($existing)) {
            return $this;
        }

        // Block position is left as "custom" so Amasty never auto-renders its own
        // layout block for this group - Ahy\PDPRevamp\Block\Product\View\CustomersAlsoBought
        // looks the group up by name and renders it manually in catalog_product_view.xml.
        $group = $this->groupRepository->getNew();
        $group->setStatus(1);
        $group->setPriority(10);
        $group->setName(self::GROUP_NAME);
        $group->setBlockPosition(BlockPosition::CUSTOM);
        $group->setStores('0,1');
        $group->setCustomerGroupIds('0,1,2,3');
        $group->setWhereConditionsSerialized(
            '{"type":"Magento\\\\CatalogRule\\\\Model\\\\Rule\\\\Condition\\\\Combine",'
            . '"attribute":null,"operator":null,"value":"1","is_value_processed":null,"aggregator":"all"}'
        );
        $group->setConditionsSerialized(
            '{"type":"Magento\\\\CatalogRule\\\\Model\\\\Rule\\\\Condition\\\\Combine",'
            . '"attribute":null,"operator":null,"value":"1","is_value_processed":null,"aggregator":"all"}'
        );
        $group->setBlockTitle(self::GROUP_NAME);
        $group->setBlockLayout(0);
        $group->setSourceType(SourceType::SOURCE_BOUGHT);
        $group->setSameAs(false);
        $group->setReplaceType(0);
        $group->setAddToCart(1);
        $group->setMaxProducts(10);
        $group->setShowForOutOfStock(0);
        $group->setShowOutOfStock(0);
        $group->setIsCurrentCategoryOnly(false);
        $group->setDisplayWishlistButton(false);
        $group->setDisplayCompareButton(false);

        $this->groupRepository->save($group);

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
