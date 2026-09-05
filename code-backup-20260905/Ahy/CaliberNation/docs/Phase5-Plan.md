# Caliber Nation — Phase 5 (Renewal-Card Integrity)

> **Status:** ✅ **Implemented (2026-07-20).** Phases 1–4 complete. Phase 5 makes **auto-renew and its payment card consistent** — a member can never arm an auto-renewal with no valid card behind it, and the UI never shows a renewal card that won't actually be charged.
>
> **Core invariant (final):** `payment_token_id` set **⟺** `auto_renew = 1`. A bound renewal card and the auto-renew switch can never disagree.
>
> **No DB schema changes for the renewal-card work** — it reuses `ahy_caliber_nation_membership.payment_token_id`, Magento Vault (`vault_payment_token`), and `activity_log`. *(The adjacent lifecycle-email work in §7 does add two tracking columns.)*
>
> **BUILD STATUS (2026-07-20):** ✅ **P5.1** card-delete → auto-renew off · ✅ **P5.2** server guard · ✅ **P5.3** card picker on enable · ✅ **P5.4** change renewal card · ✅ **P5.5** inline add-card modal + return-here-after-save · ✅ **P5.6** display consistency (usable-card only, Renews/Expires label, post-change reload) · ✅ **P5.7** UX polish. Lint/DI verified; exercised live on member 17189.
>
> **Adjacent work completed this session (see §7):** lifecycle emails (expiry / advance-notice / win-back), membership signup during account registration (modal), average-savings messaging (admin fallback + branded restyle).

---

## 0. Requirement → workstream mapping
| Requirement | Workstream | Status |
|---|---|---|
| Deleting the membership card turns auto-renew off | **P5.1** | ✅ |
| Can't enable auto-renew without a usable card; else "Add a card…" | **P5.2** | ✅ |
| Enabling shows a picker; chosen card is bound | **P5.3** | ✅ |
| Member can switch to a different renewal card any time | **P5.4** | ✅ |
| Add a card without leaving the membership page | **P5.5** | ✅ |
| Displayed card / labels always match the real state | **P5.6** | ✅ |

---

## 1. Locked decisions
1. **A membership renews on ONE specific saved card** (`payment_token_id`), not "any card on file."
2. **Card-assignment UX = explicit picker.** The member consciously chooses which saved card renews (radio list of masked cards), not a silent default.
3. **"Usable card"** = a Vault token from `PaymentTokenManagementInterface::getVisibleAvailableTokens($customerId)` — active, visible, non-expired. Same set the "Saved Payment Methods" UI shows, so the two never disagree.
4. **Server guard is authoritative.** The disabled toggle / picker is UX; enforcement lives in `toggleAutoRenew`, so bypassing the UI still cannot arm a cardless renewal.
5. **~~Turning auto-renew OFF keeps the bound token~~ → SUPERSEDED.** During testing this produced a confusing "card bound but auto-renew off" state. **Final rule (the invariant):** turning auto-renew **OFF clears** `payment_token_id`; **assigning a card turns auto-renew ON**. A renewal card is shown only when it will actually be charged.

---

## 2. Workstreams

### P5.1 · Card delete → auto-renew off
- `Plugin/DisableAutoRenewOnCardDelete` on `Magento\Vault\Api\PaymentTokenRepositoryInterface::delete`: if the deleted token is the membership's `payment_token_id`, **always clear `payment_token_id`** (drops the dangling reference), and if `auto_renew` was on, set it off. Logs `auto_renew_toggle` — "disabled — membership renewal card was deleted" (was on) or "renewal card removed — card was deleted" (was off). Repository-level hook covers all delete paths.
  - *Refinement:* originally only acted when `auto_renew=1`; that left a stale token bound after deleting a card while auto-renew was off. Now it clears regardless.
- `ViewModel/MembershipCard::getRenewalTokenId()` — exposes the renewal token id (only when active + auto-renew on) so the account **delete modal** warns for that card only: *"This card is used for your Caliber Nation membership renewal. Deleting it will turn off auto-renew…"* (in `Magento_Customer/templates/account/dashboard/cards.phtml`).

### P5.2 · Guard enabling auto-renew (authoritative, server-side)
`MembershipManagementService::toggleAutoRenew(int $customerId, bool $enable, ?int $selectedTokenId = null)` (injects `PaymentTokenManagementInterface`):
- **Disable** → `auto_renew=0` **and clears `payment_token_id`** (invariant), logs "disabled".
- **Enable**:
  1. 0 usable tokens → `['success'=>false, 'needs_card'=>true]`, message *"Add a saved card before enabling auto-renew."*
  2. A still-usable card already bound → `auto_renew=1` directly (no picker).
  3. Else require `$selectedTokenId`, validated via `findUsableToken()` (customer-owned + usable). Missing/invalid → `['success'=>false, 'needs_choice'=>true]`. Valid → bind it + `auto_renew=1`. Logs "enabled, card ••••NNNN".
- Foreign/unusable `$selectedTokenId` is rejected (ownership check).

### P5.3 · Card picker on enable (UI)
`Magewire/Account/MembershipManager`:
- State: `usableCards` (`[{id,label}]`), `selectedTokenId` (**untyped** — see P5.7 bug note), `showCardPicker`, `pickerMode` ('enable'|'change'), `boundTokenId`, `boundCardLabel`, `hasUsableCard`, `reload`.
- `toggleAutoRenew()` sends no card on the initial enable → server returns `needs_choice` → `showCardPicker=true`. `confirmCard()` sends the chosen card.
- `templates/account/membership-manager.phtml`:
  - **No usable card** → toggle `disabled` + greyed, *"Add a card to enable auto-renew — Add a card"* (opens the P5.5 modal).
  - **Needs a choice** → radio list of `usableCards` ("VISA ••1111 · Exp 06/2031"), Enable / Cancel.
  - **Valid card already bound** → one-click toggle, no picker.

### P5.4 · Change renewal card any time
- **"Renewal card: VISA ••1111 · Exp 06/2031 — Change"** line shown whenever a card is bound (i.e. auto-renew on, per the invariant).
- **Change** reopens the picker (`pickerMode='change'`, preselect current).
- `setRenewalCard(int $customerId, int $selectedTokenId)`: validate owned + usable, set `payment_token_id`, **and set `auto_renew=1`** (assigning a card implies intent to renew — invariant). Logs "renewal card set to ••••NNNN".

### P5.5 · Inline add-card modal + return-here-after-save
- The **"Add a card"** prompt opens the **same** `Ahy_Authorizenet::inter-new-card.phtml` modal used on the account dashboard — rendered once in the parent `membership-overview.phtml` (outside the Magewire component) and opened via an Alpine window event (`cn-open-add-card`) dispatched from the manager. No redirect to the dashboard.
- **Return-here-after-save (opt-in, dashboard unchanged):** `inter-new-card.phtml` emits a hidden `referer` input **only when** a `redirect_url` block-arg is set; the membership modal sets it to `caliber-nation/account`. `Ahy_Authorizenet\Controller\Account\CreateSavedCard` reads `referer`, base64-decodes it, and redirects there **only if same-origin** (open-redirect guard); absent/invalid → default `customer/account`. The dashboard doesn't set `redirect_url`, so its behaviour is unchanged.

### P5.6 · Display consistency
- **Payment Method** (`ViewModel/Account/MembershipOverview`): new `getBoundUsableToken()` requires `is_active=1 AND is_visible=1`, so a soft-deleted/expired bound card shows **"Not set"** (never a card that can't be charged). Both summary + expiry go through it.
- **"Renews On" ↔ "Expires On"** label driven by `isAutoRenewEnabled()` (on → "Renews On", off → "Expires On").
- **Post-change reload:** the overview card (Payment Method / Renews-Expires / card art) is server-rendered outside the Magewire component, so it doesn't react to a toggle. After any successful enable / disable / card change, the component sets `reload=true` and the template reloads after ~1.2s (shows the success toast first), so the overview always matches. Mirrors the cancellation-flow reload.

### P5.7 · UX polish (found during live testing)
- **Bug — Magewire type error:** `selectedTokenId` was typed `?int`, but `wire:model` on the radio delivers a **string** → `SyncInput` threw *"Cannot assign string to property … of type ?int."* Fixed by making the property **untyped** and casting `(int)` at every use; guard is `if (!$this->selectedTokenId)`.
- **FOUC (`x-cloak`):** the cancel-confirmation panel and the rejoin "Reactivate your membership?" panel (`x-show`) flashed visible on load before Alpine hid them — added `x-cloak`.
- **"CANCELLED" label color:** used `text-white/35`, an opacity-modifier class not generated in this Hyva build → fell back to a dark inherited color. Replaced with `text-white` + inline `opacity:.55`.
- **Loading buttons one-line:** "Cancelling…" / "Reactivating…" spinner+text stacked because Magewire's `wire:loading` overrides the `inline-flex` display via inline style. Switched the SVG to `inline-block align-middle mr-2` in a `whitespace-nowrap` span (flex-independent).
- **Secondary buttons disabled while busy:** "Not Yet", "No, Keep It", and the picker "Cancel" now carry `wire:loading.attr="disabled"` + `wire:target` so they grey out during the in-flight action.
- **Spacing:** reduced the cancelled-card logo bottom margin (`mb-8` → `mb-3`).

---

## 3. Config / data additions
- **No new config, no schema changes.**
- Reuses `payment_token_id`, `PaymentTokenManagementInterface`, `ActivityLogger::ACTION_AUTO_RENEW`.

---

## 4. Files touched
- **`Model/Service/MembershipManagementService.php`** — guard + invariant in `toggleAutoRenew`; `setRenewalCard`; `getUsableCards`; `findUsableToken`; `cardLabel`; `PaymentTokenManagementInterface` dep.
- **`Magewire/Account/MembershipManager.php`** — card state, picker/change handlers, `reload`, untyped `selectedTokenId`.
- **`view/frontend/templates/account/membership-manager.phtml`** — guarded toggle, picker, prompt, renewal-card + Change, reload trigger, loading/`x-cloak`/disabled polish.
- **`view/frontend/templates/account/membership-overview.phtml`** — add-card modal host, Renews/Expires label, `text-white` label fix, logo spacing.
- **`view/frontend/templates/account/membership-rejoin.phtml`** — `x-cloak`, inline spinner, "Not Yet" disabled.
- **`ViewModel/Account/MembershipOverview.php`** — `getBoundUsableToken()` (usable-only Payment Method).
- **`Plugin/DisableAutoRenewOnCardDelete.php`** + `etc/di.xml` — clears token on any delete of the bound card.
- **`ViewModel/MembershipCard.php`** — renewal-token id for the delete-modal warning.
- **`Magento_Customer/templates/account/dashboard/cards.phtml`** — delete-modal warning for the membership card.
- **`Ahy_Authorizenet` (shared, opt-in only):** `Controller/Account/CreateSavedCard.php` (same-origin `referer` redirect) + `view/frontend/templates/inter-new-card.phtml` (hidden `referer` when `redirect_url` set).

---

## 5. Acceptance criteria (all met)
- **Invariant:** at no point can `payment_token_id` be set while `auto_renew=0`, or vice-versa. Disable clears the card; assigning a card enables.
- **P5.1:** deleting the bound card clears `payment_token_id` (and turns off auto-renew if it was on) + logs; delete modal warns for that card only. *(verified live — activity log confirmed)*
- **P5.2:** 0 usable cards → enable rejected with "Add a saved card…"; valid bound card → one-click enable; foreign/unusable token rejected.
- **P5.3:** no card → toggle disabled + prompt; needs-choice → picker lists exactly the account's usable cards; choosing sets `auto_renew=1` + `payment_token_id`.
- **P5.4:** Change binds a different usable card and keeps auto-renew on; logged.
- **P5.5:** "Add a card" opens the modal in place; after Save the user returns to the membership page; dashboard add-card still lands on the dashboard.
- **P5.6:** Payment Method shows "Not set" for a deleted/expired bound card; label reads Renews/Expires per state; overview refreshes after a change.
- Emails remain disabled on local; no delivery side effects.

---

## 6. Risks / notes
- **Token source consistency** — always `getVisibleAvailableTokens`; never read `vault_payment_token` directly (would include soft-deleted/expired rows).
- **Ownership check** — `selectedTokenId` validated against the customer's own usable tokens (anti-forgery).
- **Expired-but-bound edge** — if the bound token expires, the "still usable" check fails, so the member must re-pick before re-enabling; Payment Method shows "Not set".
- **Parent vs Magewire** — overview fields live outside the reactive component; kept in sync via the post-change reload (P5.6), not live binding.
- **Cancellation vs invariant (open follow-up):** `cancelMembership()` sets `auto_renew=0` but does **not** yet clear `payment_token_id`, so a cancelled membership can still carry a bound token — the same contradiction the invariant removes for the toggle. Consider clearing it there too for full consistency.
- **Cross-module** — Caliber logic stays in `Ahy_CaliberNation`; the only `Ahy_Authorizenet` edits are the opt-in `referer` redirect (dashboard behaviour unchanged).

---

## 7. Related work completed this session (adjacent to Phase 5)
Built alongside the renewal-card work but belonging to other feature-list areas (also tracked in `Pending-Implementation.md`).

### 7.1 · Lifecycle emails — expiry / advance-notice / win-back  *(new feature)*
- **Templates + registration:** `caliber_nation_expiry`, `caliber_nation_renewal_reminder`, `caliber_nation_winback` (`view/frontend/email/*.html` + `email_templates.xml`).
- **Admin group `Caliber Nation → Lifecycle Emails`:** `expiry_enabled`, `renewal_reminder_enabled` + `renewal_reminder_days` (default 7), `winback_enabled` (Config getters + `config.xml` defaults + `system.xml`).
- **Schema (the two tracking columns):** `ahy_caliber_nation_membership.renewal_reminder_sent_at` and `winback_email_sent_at` (both nullable datetime) for send **dedup** — reminder once per renewal cycle (reset on successful renewal), win-back once per lapse (reset on (re)activation in `ActivateMembership`).
- **Send points:** expiry email from **both** expiry paths (`RenewMembership::handleFailure` after max failed renewals, and the paid-through sweep in `ProcessRenewals`); advance reminder + win-back added as new sweeps in `ProcessRenewals::execute()`. All config-gated, best-effort, disabled-on-local (verify via Email Log).
- **Status:** built + DI/lint verified; live email-render check via the Email Log still pending.

### 7.2 · Membership signup during account registration  *(new feature)*
- The **"Join & Save"** banner on `customer/account/create` now opens the **same signup form in a modal** (blurred backdrop) instead of redirecting to `/caliber-nation`.
- Reuses the `MembershipSignup` Magewire component via a new **`form_only`** template mode (drops the marketing header + left brand panel, renders just the account form). Modal host: `cta/register-signup-modal.phtml`; trigger dispatched from `cta/join-banner.phtml` (`open-caliber-signup`).
- Distinct block name `caliber_nation_membership_signup_register` + Magewire handle mapping (`→ customer_account_create`) so AJAX reconstruction works off the CMS page. No server/controller changes — reuses the existing create-account → add-to-cart → checkout → `ActivateOnOrderPlace` flow.

### 7.3 · "Average savings" messaging  *(polish)*
- Admin static fallback (`messaging/average_savings_static`, labeled **Average Savings Fallback (amount)**) so the block shows before ≥5 real member orders exist.
- Re-styled to the brand (white card, league-gothic amount in ahy-red, red divider). The landing "You're Already a Member" panel was also centered + enlarged.

### 7.4 · Styling note — inline CSS vs Tailwind
This Hyva theme ships a **pre-built, purged** Tailwind stylesheet: utility classes not present in templates at build time (e.g. `py-10`, `my-12`, `backdrop-blur-lg`, `text-white/35`) are purged and have **no effect** at runtime. Several spacing / blur / opacity tweaks were therefore applied as **inline `style`** to avoid a Tailwind rebuild. Running the theme's Tailwind build (`npm run build` in the theme + `setup:static-content:deploy`) would let these be normal classes; converting the inline styles back to classes is a follow-up.
