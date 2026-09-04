<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin\Customer\CustomerData;

use Magento\Customer\CustomerData\Customer;
use Magento\Customer\Model\Session;

/**
 * Adds 'email' to the stock 'customer' private-content section, which
 * Magento's own Customer::getSectionData() does not include (it returns
 * fullname/firstname/websiteId only). The Yotpo review form's
 * getCustomerData() (yotpo_js.phtml) reads customerData.customer.email to
 * auto-fill a logged-in shopper's review submission, and resets both name
 * and email to empty whenever either is missing - so without this, every
 * logged-in customer's review was submitted as "Anonymous" /
 * "guest@review.everest.com" instead of their real details.
 */
class AddEmailToCustomerSection
{
    public function __construct(
        private readonly Session $customerSession
    ) {
    }

    public function afterGetSectionData(Customer $subject, array $result): array
    {
        if ($this->customerSession->isLoggedIn()) {
            $result['email'] = $this->customerSession->getCustomer()->getEmail();
        }

        return $result;
    }
}
