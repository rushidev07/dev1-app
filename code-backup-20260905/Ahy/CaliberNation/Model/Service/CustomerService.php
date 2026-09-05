<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Model\Service;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

class CustomerService
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private AccountManagementInterface  $accountManagement,
        private CustomerInterfaceFactory    $customerFactory,
        private StoreManagerInterface       $storeManager,
        private ResourceConnection          $resource,
        private AddressInterfaceFactory     $addressFactory,
        private AddressRepositoryInterface  $addressRepository,
        private RegionInterfaceFactory      $regionFactory
    ) {}

    public function emailExists(string $email): bool
    {
        try {
            $this->customerRepository->get($email);
            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    /** Check if the email belongs to an active Caliber Nation member. */
    public function isActiveMember(string $email): bool
    {
        try {
            $customer = $this->customerRepository->get($email);
        } catch (NoSuchEntityException) {
            return false;
        }

        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('ahy_caliber_nation_membership');

        return (bool) $conn->fetchOne(
            $conn->select()
                ->from($table, ['entity_id'])
                ->where('customer_id = ?', (int) $customer->getId())
                ->where('status = ?', 'active')
        );
    }

    /**
     * Create a Magento customer account using the password the user chose.
     * Returns the new customer_id.
     *
     * @throws LocalizedException
     */
    public function createAccount(
        string $firstName,
        string $lastName,
        string $email,
        string $password
    ): int {
        $store    = $this->storeManager->getStore();
        $customer = $this->customerFactory->create();
        $customer->setFirstname($firstName)
                 ->setLastname($lastName)
                 ->setEmail($email)
                 ->setStoreId((int) $store->getId())
                 ->setWebsiteId((int) $store->getWebsiteId());

        $created = $this->accountManagement->createAccount($customer, $password);

        return (int) $created->getId();
    }

    public function getCustomerIdByEmail(string $email): ?int
    {
        try {
            return (int) $this->customerRepository->get($email)->getId();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /** Whether the customer already has a default billing address on file. */
    public function hasDefaultBilling(int $customerId): bool
    {
        try {
            return (bool) $this->customerRepository->getById($customerId)->getDefaultBilling();
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    /**
     * Create a default billing (+ shipping) address for the customer.
     * A billing address is mandatory to place the membership order at checkout.
     *
     * @throws LocalizedException
     */
    public function createDefaultBillingAddress(
        int    $customerId,
        string $firstName,
        string $lastName,
        string $street,
        string $city,
        int    $regionId,
        string $postcode,
        string $telephone,
        string $countryId = 'US'
    ): void {
        $region = $this->regionFactory->create();
        $region->setRegionId($regionId);

        $address = $this->addressFactory->create();
        $address->setCustomerId($customerId)
                ->setFirstname($firstName)
                ->setLastname($lastName)
                ->setStreet([$street])
                ->setCity($city)
                ->setRegionId($regionId)
                ->setRegion($region)
                ->setPostcode($postcode)
                ->setCountryId($countryId)
                ->setTelephone($telephone)
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true);

        $this->addressRepository->save($address);
    }

    /**
     * Return the customer id for an email, creating an account if none exists.
     * Used for guest membership orders (spec §7): the account is created with no
     * password so Magento emails a "set password" link.
     */
    public function getOrCreateByEmail(string $email, string $firstName = '', string $lastName = ''): int
    {
        $email    = strtolower(trim($email));
        $existing = $this->getCustomerIdByEmail($email);
        if ($existing) {
            return $existing;
        }

        $store    = $this->storeManager->getStore();
        $customer = $this->customerFactory->create();
        $customer->setFirstname($firstName ?: 'Member')
                 ->setLastname($lastName ?: 'Member')
                 ->setEmail($email)
                 ->setStoreId((int) $store->getId())
                 ->setWebsiteId((int) $store->getWebsiteId());

        // No password → Magento sends the new-account / set-password email.
        $created = $this->accountManagement->createAccount($customer);
        return (int) $created->getId();
    }
}
