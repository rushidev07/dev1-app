<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Api;

use Magento\Framework\Api\SearchCriteriaInterface;

interface MembershipRepositoryInterface
{
    /**
     * Save membership.
     *
     * @param \Ahy\CaliberNation\Api\Data\MembershipInterface $membership
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(\Ahy\CaliberNation\Api\Data\MembershipInterface $membership);

    /**
     * Get membership by entity id.
     *
     * @param int $entityId
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById($entityId);

    /**
     * Get the membership record for a customer (one row per customer).
     *
     * @param int $customerId
     * @return \Ahy\CaliberNation\Api\Data\MembershipInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getByCustomerId($customerId);

    /**
     * Get memberships matching the specified criteria.
     *
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Ahy\CaliberNation\Api\Data\MembershipSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria);

    /**
     * Delete membership.
     *
     * @param \Ahy\CaliberNation\Api\Data\MembershipInterface $membership
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(\Ahy\CaliberNation\Api\Data\MembershipInterface $membership);

    /**
     * Delete membership by id.
     *
     * @param int $entityId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById($entityId);
}
