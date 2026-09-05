<?php
declare(strict_types=1);

namespace Ahy\CaliberNation\Magewire\Account;

use Ahy\CaliberNation\Api\MembershipRepositoryInterface;
use Ahy\CaliberNation\Model\Config;
use Ahy\CaliberNation\Model\Service\MembershipManagementService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magewirephp\Magewire\Component;

class MembershipManager extends Component
{
    // ── Reactive state ────────────────────────────────────────────────────────
    public bool   $autoRenew          = false;
    public bool   $showCancelConfirm  = false;
    public bool   $cancellationDone   = false;
    public string $errorMessage       = '';
    public string $successMessage     = '';
    // Formatted renewal_date — cancelling is PAID-THROUGH, so the confirm prompt
    // must tell the member which date their benefits actually run until.
    public string $paidThroughDate     = '';

    // Renewal-card state (Phase 5)
    public bool   $hasUsableCard      = false;
    public array  $usableCards        = [];        // [['id' => int, 'label' => string], ...]
    // Untyped on purpose: wire:model on the radio delivers a string; we cast to int
    // on use. A typed ?int here makes Magewire's SyncInput throw on the string assign.
    public        $selectedTokenId    = null;
    public bool   $showCardPicker     = false;
    public string $pickerMode         = 'enable';  // 'enable' | 'change'
    public ?int   $boundTokenId       = null;
    public string $boundCardLabel     = '';
    // Set true after a successful state change so the (server-rendered) overview
    // card — Payment Method / Renews-Expires — is refreshed via a short-delay reload.
    public bool   $reload             = false;

    public function __construct(
        private readonly MembershipManagementService  $managementService,
        private readonly MembershipRepositoryInterface $membershipRepository,
        private readonly CustomerSession               $customerSession,
        private readonly Config                        $config
    ) {}

    /**
     * Master-switch guard for mutating actions. Returns true (and sets an error
     * message) when the program is off, so callers can bail. Defends against a
     * stale browser tab POSTing to this component after the program is disabled.
     */
    private function programDisabled(): bool
    {
        if (!$this->config->isEnabled()) {
            $this->errorMessage = 'Caliber Nation membership is currently unavailable.';
            return true;
        }
        return false;
    }

    /**
     * Initialise reactive state from the membership record.
     * The parent template (membership-overview.phtml) only renders this component
     * for active members, so a missing record here is a safe no-op.
     */
    public function mount(): void
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        if (!$customerId) {
            return;
        }

        try {
            $membership         = $this->membershipRepository->getByCustomerId($customerId);
            $this->autoRenew    = (bool) $membership->getAutoRenew();
            $this->boundTokenId = $membership->getPaymentTokenId() ? (int) $membership->getPaymentTokenId() : null;

            $renewal = $membership->getRenewalDate();
            $this->paidThroughDate = $renewal ? date('F j, Y', strtotime((string) $renewal)) : '';
        } catch (NoSuchEntityException) {
            // No membership record — parent template guards this; nothing to initialise.
            return;
        }

        $this->loadCards($customerId);
    }

    // ── Auto-renew toggle ─────────────────────────────────────────────────────

    public function toggleAutoRenew(): void
    {
        $this->resetFeedback();
        if ($this->programDisabled()) {
            return;
        }
        $customerId = (int) $this->customerSession->getCustomerId();

        // Disabling turns off auto-renew and unbinds the renewal card (invariant:
        // no auto-renew → no renewal card).
        if ($this->autoRenew) {
            $result = $this->managementService->toggleAutoRenew($customerId, false);
            if ($result['success']) {
                $this->autoRenew      = false;
                $this->successMessage = $result['message'];
                $this->refreshBound($customerId);
                $this->reload = true;
            } else {
                $this->errorMessage = $result['message'];
            }
            return;
        }

        // Enabling: don't pre-send a card, so the picker is shown unless a valid
        // card is already bound (server enables directly in that case).
        $result = $this->managementService->toggleAutoRenew($customerId, true, null);
        $this->applyEnableResult($result, $customerId);
    }

    /** Confirm button inside the card picker (enable flow OR change flow). */
    public function confirmCard(): void
    {
        $this->resetFeedback();
        if ($this->programDisabled()) {
            return;
        }
        $customerId = (int) $this->customerSession->getCustomerId();

        if (!$this->selectedTokenId) {
            $this->errorMessage = 'Please choose a card.';
            return;
        }

        if ($this->pickerMode === 'change') {
            $result = $this->managementService->setRenewalCard($customerId, (int) $this->selectedTokenId);
            if ($result['success']) {
                $this->showCardPicker = false;
                $this->successMessage = $result['message'];
                $this->autoRenew      = true; // assigning a card turns auto-renew on
                $this->refreshBound($customerId);
                $this->reload = true;
            } else {
                $this->errorMessage = $result['message'];
            }
            return;
        }

        // Enable flow — pass the chosen card.
        $result = $this->managementService->toggleAutoRenew($customerId, true, (int) $this->selectedTokenId);
        $this->applyEnableResult($result, $customerId);
    }

    /** "Change" link — reopen the picker to bind a different card. */
    public function changeRenewalCard(): void
    {
        $this->resetFeedback();
        if ($this->programDisabled()) {
            return;
        }
        $this->pickerMode      = 'change';
        $this->selectedTokenId = $this->boundTokenId ?? ($this->usableCards[0]['id'] ?? null);
        $this->showCardPicker  = true;
    }

    public function dismissCardPicker(): void
    {
        $this->resetFeedback();
        $this->showCardPicker = false;
    }

    // ── Private helpers (renewal card) ─────────────────────────────────────────

    private function applyEnableResult(array $result, int $customerId): void
    {
        if (!empty($result['success'])) {
            $this->autoRenew      = true;
            $this->showCardPicker = false;
            $this->successMessage = $result['message'];
            $this->refreshBound($customerId);
            $this->reload = true;
            return;
        }

        if (!empty($result['needs_choice'])) {
            // Cards exist but none bound — reveal the picker.
            $this->pickerMode     = 'enable';
            $this->showCardPicker = true;
            if ($this->selectedTokenId === null && !empty($this->usableCards)) {
                $this->selectedTokenId = (int) $this->usableCards[0]['id'];
            }
            return;
        }

        // needs_card / other errors.
        $this->errorMessage = $result['message'];
    }

    private function loadCards(int $customerId): void
    {
        $this->usableCards   = $this->managementService->getUsableCards($customerId);
        $this->hasUsableCard = !empty($this->usableCards);
        $this->resolveBoundLabel();

        if ($this->selectedTokenId === null) {
            $this->selectedTokenId = $this->boundTokenId ?? ($this->usableCards[0]['id'] ?? null);
        }
    }

    private function refreshBound(int $customerId): void
    {
        try {
            $membership         = $this->membershipRepository->getByCustomerId($customerId);
            $this->boundTokenId = $membership->getPaymentTokenId() ? (int) $membership->getPaymentTokenId() : null;
        } catch (NoSuchEntityException) {
            $this->boundTokenId = null;
        }
        $this->resolveBoundLabel();
    }

    private function resolveBoundLabel(): void
    {
        $this->boundCardLabel = '';
        foreach ($this->usableCards as $card) {
            if ((int) $card['id'] === (int) $this->boundTokenId) {
                $this->boundCardLabel = (string) $card['label'];
                break;
            }
        }
    }

    // ── Cancellation flow ─────────────────────────────────────────────────────

    public function showCancelConfirm(): void
    {
        $this->resetFeedback();
        $this->showCancelConfirm = true;
    }

    public function dismissCancel(): void
    {
        $this->showCancelConfirm = false;
    }

    public function cancelMembership(): void
    {
        $this->resetFeedback();
        if ($this->programDisabled()) {
            return;
        }

        $result = $this->managementService->cancelMembership(
            (int) $this->customerSession->getCustomerId()
        );

        if ($result['success']) {
            $this->cancellationDone  = true;
            $this->showCancelConfirm = false;
            $this->successMessage    = $result['message'];
        } else {
            $this->showCancelConfirm = false;
            $this->errorMessage      = $result['message'];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resetFeedback(): void
    {
        $this->errorMessage   = '';
        $this->successMessage = '';
        $this->reload         = false;
    }
}
