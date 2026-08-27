<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Setup\Patch\Data;

use Magento\Customer\Api\Data\GroupInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates the dedicated "Caliber Nation Member" customer group and persists its
 * id to config (caliber_nation/general/member_group_id) so nothing is hardcoded.
 */
class CreateCaliberMemberGroup implements DataPatchInterface
{
    private const GROUP_CODE       = 'Caliber Nation Member';
    private const CONFIG_GROUP_ID  = 'caliber_nation/general/member_group_id';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly GroupInterfaceFactory $groupFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly WriterInterface $configWriter,
        private readonly LoggerInterface $logger
    ) {}

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $groupId = $this->findExistingGroupId();

            if ($groupId === null) {
                // Reuse the default "General" group's tax class so the new group is valid.
                $taxClassId = (int) $this->groupRepository->getById(1)->getTaxClassId();

                $group = $this->groupFactory->create();
                $group->setCode(self::GROUP_CODE);
                $group->setTaxClassId($taxClassId ?: 3);
                $group = $this->groupRepository->save($group);
                $groupId = (int) $group->getId();
            }

            $this->configWriter->save(self::CONFIG_GROUP_ID, (string) $groupId);
            $this->logger->info('[CaliberNation] Member group ready. group_id=' . $groupId);
        } catch (\Exception $e) {
            $this->logger->error('[CaliberNation] CreateCaliberMemberGroup failed: ' . $e->getMessage());
        }

        $this->moduleDataSetup->getConnection()->endSetup();
        return $this;
    }

    private function findExistingGroupId(): ?int
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_group_code', self::GROUP_CODE)
            ->create();

        foreach ($this->groupRepository->getList($criteria)->getItems() as $group) {
            return (int) $group->getId();
        }
        return null;
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
