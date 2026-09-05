<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Ahy\CaliberNation\Model;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\Data\MembershipInterfaceFactory;
use Ahy\CaliberNation\Api\Data\MembershipSearchResultsInterfaceFactory;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\ResourceModel\Membership as MembershipResource;
use Ahy\CaliberNation\Model\ResourceModel\Membership\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class MembershipRepository implements MembershipRepositoryInterface
{
    /**
     * @var MembershipResource
     */
    private $resource;

    /**
     * @var MembershipInterfaceFactory
     */
    private $membershipFactory;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var MembershipSearchResultsInterfaceFactory
     */
    private $searchResultsFactory;

    /**
     * @var CollectionProcessorInterface
     */
    private $collectionProcessor;

    /**
     * @param MembershipResource $resource
     * @param MembershipInterfaceFactory $membershipFactory
     * @param CollectionFactory $collectionFactory
     * @param MembershipSearchResultsInterfaceFactory $searchResultsFactory
     * @param CollectionProcessorInterface $collectionProcessor
     */
    public function __construct(
        MembershipResource $resource,
        MembershipInterfaceFactory $membershipFactory,
        CollectionFactory $collectionFactory,
        MembershipSearchResultsInterfaceFactory $searchResultsFactory,
        CollectionProcessorInterface $collectionProcessor
    ) {
        $this->resource = $resource;
        $this->membershipFactory = $membershipFactory;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->collectionProcessor = $collectionProcessor;
    }

    /**
     * @inheritDoc
     */
    public function save(MembershipInterface $membership)
    {
        try {
            $this->resource->save($membership);
        } catch (\Exception $exception) {
            throw new CouldNotSaveException(
                __('Could not save the membership: %1', $exception->getMessage())
            );
        }
        return $membership;
    }

    /**
     * @inheritDoc
     */
    public function getById($entityId)
    {
        /** @var \Ahy\CaliberNation\Model\Membership $membership */
        $membership = $this->membershipFactory->create();
        $this->resource->load($membership, $entityId);
        if (!$membership->getEntityId()) {
            throw new NoSuchEntityException(
                __('Membership with id "%1" does not exist.', $entityId)
            );
        }
        return $membership;
    }

    /**
     * @inheritDoc
     */
    public function getByCustomerId($customerId)
    {
        /** @var \Ahy\CaliberNation\Model\Membership $membership */
        $membership = $this->membershipFactory->create();
        $this->resource->load($membership, $customerId, MembershipInterface::CUSTOMER_ID);
        if (!$membership->getEntityId()) {
            throw new NoSuchEntityException(
                __('No membership found for customer id "%1".', $customerId)
            );
        }
        return $membership;
    }

    /**
     * @inheritDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria)
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());
        return $searchResults;
    }

    /**
     * @inheritDoc
     */
    public function delete(MembershipInterface $membership)
    {
        try {
            $this->resource->delete($membership);
        } catch (\Exception $exception) {
            throw new CouldNotDeleteException(
                __('Could not delete the membership: %1', $exception->getMessage())
            );
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteById($entityId)
    {
        return $this->delete($this->getById($entityId));
    }
}
