<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Magewire\Account;

use Ahy\CaliberNation\Api\Data\MembershipInterface;
use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MembershipManagementService;
use Ahy\CaliberNation\Model\Service\MembershipSignupService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magewirephp\Magewire\Component;

class MembershipRejoin extends Component
{
    public bool   $rejoined     = false;
    public string $errorMessage = '';

    /**
     * True when the member cancelled but is STILL inside the term they paid for —
     * benefits have NOT stopped. The whole panel switches to "resume" wording in
     * that case, because "rejoin to regain access" would be factually wrong.
     */
    public bool   $withinPaidThrough = false;
    /** Formatted renewal_date — the date benefits actually run until. */
    public string $paidThroughDate   = '';
    /** Whether a usable saved card is bound (decides if resuming restores auto-renew). */
    public bool   $hasRenewalCard    = false;

    public function __construct(
        private MembershipSignupService       $signupService,
        private MembershipManagementService   $managementService,
        private MembershipRepositoryInterface $membershipRepository,
        private CustomerSession               $customerSession,
        private Config                        $config
    ) {}

    public function mount(): void
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$customerId) {
            return;
        }

        try {
            $membership = $this->membershipRepository->getByCustomerId($customerId);
        } catch (NoSuchEntityException) {
            return;
        }

        $renewal = $membership->getRenewalDate();
        if (!$renewal) {
            return;
        }

        $this->paidThroughDate   = date('F j, Y', strtotime((string) $renewal));
        $this->withinPaidThrough = $membership->getStatus() === MembershipInterface::STATUS_CANCELLED
            && strtotime((string) $renewal) > time();

        if ($this->withinPaidThrough && $membership->getPaymentTokenId()) {
            $boundId = (int) $membership->getPaymentTokenId();
            foreach ($this->managementService->getUsableCards($customerId) as $card) {
                if ((int) $card['id'] === $boundId) {
                    $this->hasRenewalCard = true;
                    break;
                }
            }
        }
    }

    /**
     * Called when the logged-in ex-member confirms.
     * No OTP needed — the customer session already proves identity.
     *
     * Two distinct paths:
     *  - Still inside the paid-through window → RESUME (status back to active, term
     *    untouched). Activating afresh here would reset renewal_date to +1 term
     *    without a charge, i.e. a free year.
     *  - Term already over → genuine rejoin (new active term).
     */
    public function rejoin(): void
    {
        $this->errorMessage = '';

        if (!$this->config->isEnabled()) {
            $this->errorMessage = 'Caliber Nation membership is currently unavailable.';
            return;
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        if ($this->withinPaidThrough) {
            $result = $this->managementService->resumeMembership($customerId);
        } else {
            $customer = $this->customerSession->getCustomer();
            $result   = $this->signupService->createMembership(
                (string) $customer->getEmail(),
                MembershipInterface::TIER_ANNUAL,
                (string) $customer->getFirstname()
            );
        }

        if (!$result['success']) {
            $this->errorMessage = $result['message'];
            return;
        }

        // Triggers Alpine x-init reload in template → overview re-renders to STATE A
        $this->rejoined = true;
    }
}
