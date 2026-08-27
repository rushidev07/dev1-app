# Caliber Nation — Phase 2B Plan (Renewal, Retention & Operations)

> **Status:** Implementation plan. Phase **2A is complete & verified live** (order-based onboarding + active membership). 2B builds everything that operates on an existing member.
> **Scope:** renewal engine, win-back (incl. **admin-configurable Day-31 renewal discount**), admin management, access control, logging, landing revamp, QA/deploy.
> **Deferred (later phase):** member/seller **pricing + savings** display, Klevu, **early access (B3)**, seller import, **affiliate tracking**.
>
> **Decisions (resolved):** retry = **3 attempts / 7 days** then `expired` · **renewal does NOT create a Magento order** (reversed 2026-07-10 — see B1) · **Day-31 win-back discount = build it, admin-configurable** · early access (B3) **deferred**.
>
> **BUILD STATUS:** ✅ **B0** · ✅ **B1** (verified live; renewal = charge + log + email, no order) · ✅ **B2** (verified live) · ✅ **B4** (grid + actions, verified) · ◑ **B5** (admin-only ACL + all logging done incl. config-change; member-gating helper dropped for now) · **B3 deferred** · **remaining:** B4 messaging config, B6/B7, auto-add reliability tweak.

---

## 0. Requirement → 2B workstream mapping
| Requirement (master list) | Workstream |
|---|---|
| Reuse SavedCC tokenization for auto-renew · automatic debit via saved card | **B0 + B1** |
| Cron-based renewal execution · extend only after successful payment · handle renewal failures + status transitions | **B1** |
| Status transitions: Expired, Renewal Pending | **B1** |
| Renewal confirmation email + receipt (no pre-renewal reminders) | **B1** |
| Define renewal confirmation handling (US) | **B1** |
| Identify expired members · auto-add membership to cart · prompt at checkout · renewal popups across touchpoints | **B2** |
| Day-31 renewal discounted pricing | **B2 (admin-configurable)** |
| Early-access flags (products/sellers/customers) · admin timing & messaging · early-bird badges | **B3** |
| Admin membership grid (status/renewal/cancel/manual override) | **B4** |
| Configure renewal prompts / early-bird messaging · cancellation handling settings | **B4** |
| Middleware: admin-only management · middleware: restrict member features | **B5** |
| Log renewals · log admin config changes | **B5** |
| Revamp landing page · update FAQs (savings section EXC) | **B6** |
| Demo · staging/dev1 · revisions · cleanup · QA · deploy + validate scheduler | **B7** |

---

## 1. Data model additions
- ✅ **`ahy_caliber_nation_renewal_log`** (B1, created) — one row per renewal attempt: `entity_id`, `membership_id`, `customer_id`, `result` (`success|failed`), `amount`, `payment_token_id`, `transaction_id`, `order_id` (retained but **always NULL** — renewal order reversed 2026-07-10), `error_message`, `created_at`. Indexes on `membership_id`, `result`.
- **Early-access storage** (B3, deferred): product attribute `is_caliber_early_access` etc.
- **Reuse existing:** `ahy_caliber_nation_membership`, `ahy_caliber_nation_activity_log`, `vault_payment_token`, `ahy_authorizenet_customer_profile`.

## 2. Status lifecycle (the state machine 2B completes)
```
                 renewal_date reached + auto_renew=1
   ACTIVE ───────────────► charge saved card
     ▲                         │ success → extend renewal_date (+term), stay ACTIVE, receipt email
     │                         │ fail    → RENEWAL_PENDING
     │ rejoin/repurchase       ▼
     │                      RENEWAL_PENDING ──retry (cron, ≤3 attempts / 7 days)──► fail → EXPIRED
     │                                                                                    │
   (win-back: auto-add to cart, prompts) ◄──────────────────────────────────────────────┘
   CANCELLED ◄── self-service cancel (auto_renew off, group reverted)  [2A ✅]
```

---

## B0 · Vault-token linkage — **BUILT ✅**
**Problem (from 2A):** `ahy_caliber_nation_membership.payment_token_id` was empty because Authorize.Net vaults the card **after** `placeOrder()` (after our activation observer runs). No token link → nothing to auto-debit.

**Implemented:** `Plugin/LinkVaultTokenToMembership` (afterSave on `PaymentTokenRepositoryInterface::save`) links a freshly-vaulted token to the customer's membership when it has none — deterministic regardless of event ordering. `Setup/Patch/Data/BackfillMembershipTokens` links the latest active token for existing members.

**Files:** `Plugin/LinkVaultTokenToMembership.php`, `etc/di.xml`, `Setup/Patch/Data/BackfillMembershipTokens.php`.
**Verified:** patch applied; token linked for members that have a vault token (a member with no vaulted card stays null, as expected).

## B1 · Renewal engine — **BUILT & VERIFIED LIVE ✅**
**Cron** (`etc/crontab.xml`, daily 03:00), `Cron/ProcessRenewals`:
1. Queries memberships: `status IN (active, renewal_pending)`, `auto_renew=1`, `renewal_date <= now`.
2. Charges the saved card via **`Ahy\Authorizenet\Service\AuthorizeNetApi::chargeSavedCard()`** (the existing CIM method — no new method needed): `customer_profile_id` from `ahy_authorizenet_customer_profile` + payment-profile id from `vault_payment_token.gateway_token`.
3. **Success** → extend `renewal_date += term`, keep `ACTIVE`, write `renewal_log` (success + transId), **send renewal receipt email**, log activity `renewed`.
4. **Failure** → `RENEWAL_PENDING`, write `renewal_log` (failed + error).
5. **Retry policy:** up to **3 attempts / 7 days** (attempts counted from `renewal_log` failed rows since `renewal_date`); then → `EXPIRED`, revert member group, log.
6. **No pre-renewal reminder emails.**

**Service:** `Model/Service/RenewMembership` — single-membership renewal, reused by the cron and admin "Charge now".

**Files:** `Cron/ProcessRenewals.php`, `etc/crontab.xml`, `Model/Service/RenewMembership.php`, `etc/db_schema.xml` (`ahy_caliber_nation_renewal_log`), `etc/email_templates.xml` + `view/frontend/email/renewal.html`.

**Fixed along the way:** a latent bug in `Ahy_Authorizenet` — `chargeSavedCard()` → `logSavedCC()` used an uninjected `$appState` and fatally crashed; injected `Magento\Framework\App\State` + null-guarded it.

**Verified live:** set a member due → cron charged the saved card (sandbox transId), extended `renewal_date` +1yr, wrote a `success` renewal-log row, logged `renewed`.

**Renewal order per renewal — REVERSED ✅ (decision changed 2026-07-10):** a renewal does **NOT** create a Magento sales order. Path A was built (`MembershipRenewalOrderService` + an offline-paid order) but then dropped because it required an offline payment method (`checkmo`) to be enabled — an admin/config dependency in every environment — for what is only a receipt/accounting record. The financial trail already lives in `ahy_caliber_nation_renewal_log` (amount + Authorize.Net `transaction_id`), the `renewed` activity entry, the receipt email, and the Authorize.Net dashboard, so the order added complexity without a required capability. **Now:** a successful renewal = charge saved card → extend `renewal_date` → log → receipt email; `renewal_log.order_id` and the activity entry's `order_id` are always NULL. Self-contained, zero admin config, verified live (yavemi3671, txn 120086230530). *Files: `RenewMembership.php` (order call removed), `Observer/ActivateOnOrderPlace.php` (renewal-skip guard removed), `MembershipRenewalOrderService.php` **deleted**. `renewal_log.order_id` column retained (nullable, unused) to avoid a schema migration.*

## B2 · Win-back / expired-member retention — **BUILT & VERIFIED LIVE ✅**
- **`ExpiredMemberLocator`** — detects expired members, days-since-expiry, and win-back eligibility.
- **`ApplyWinbackPrice`** observer (`sales_quote_collect_totals_before`) — sets the discounted **custom price** on the membership line for eligible ex-members (flows through cart/checkout/order).
- **`AutoAddMembershipForExpired`** observer (`customer_login`) — auto-adds the membership to a lapsed member's cart. *(⚠️ unreliable at `customer_login` due to the post-login quote merge — recommend moving to `checkout_cart_load_after`.)*
- **`RenewalPrompt`** ViewModel + banner (cart page) — "membership expired — renew" with the discounted price when eligible. `JoinCta` refined so ex-members see the renewal prompt, not the generic "Join" banner.
- **Day-31 win-back discount — admin-configurable** (`caliber_nation/winback/*`): `enabled`, `days_threshold` (30), `discount_type` (percent/fixed), `discount_value`. Price computed in **`Config::getWinbackPrice()`** (no separate WinbackPricing class).

**Files:** `Model/Service/ExpiredMemberLocator.php`, `Observer/ApplyWinbackPrice.php`, `Observer/AutoAddMembershipForExpired.php`, `ViewModel/RenewalPrompt.php` + `view/frontend/templates/cta/renewal-prompt.phtml`, `Model/Config/Source/WinbackDiscountType.php`, `Model/Config.php` (winback getters), `etc/adminhtml/system.xml`, `etc/config.xml`, `etc/events.xml`, `ViewModel/JoinCta.php`.

**Verified live:** expired-40-days member + enabled → `isWinbackEligible=true`, membership line discounted **$99.99 → $79.99** (custom_price) in the active cart; renewal prompt shows the special price; landing panel shows the discounted price.

## B3 · Early access / early bird — **DEFERRED (per decision)**
Not built in this 2B pass. Requires business definition first: which levels (product / seller / customer), what "early access" does (badge-only vs. a member-first purchase window), and the timing model. Revisit as a follow-up phase.

## B4 · Admin membership management — **BUILT & VERIFIED ✅**
- **Grid** — UI listing `ahy_caliber_nation_membership_listing` (joined grid collection with customer email/name): ID, email, name, status (dropdown filter), tier, start/renewal dates (date-range filters), auto-renew, vault token. Menu: **Caliber Nation → Memberships**.
- **Row actions:** **Charge now** (→ `RenewMembership`), **Cancel** (→ `MembershipManagementService`, reverts group), **Set Active / Set Expired** (manual status override → syncs member group → logs `status_change` with `admin_user_id`).
- **Access:** all controllers gated by `ADMIN_RESOURCE = Ahy_CaliberNation::membership` (ACL resource added).

**Files:** `view/adminhtml/ui_component/ahy_caliber_nation_membership_listing.xml`, `view/adminhtml/layout/calibernation_membership_index.xml`, `Ui/Component/Listing/Column/MembershipActions.php`, `Model/ResourceModel/Membership/Grid/Collection.php`, `Model/Config/Source/MembershipStatus.php`, `Controller/Adminhtml/Membership/{Index,ChargeNow,Cancel,SetStatus}.php`, `etc/adminhtml/routes.xml`, `etc/adminhtml/menu.xml`, `etc/acl.xml`, `etc/di.xml` (grid collection).

**Verified:** grid collection loads memberships with joined customer data (active + expired rows).
**REMAINING:** the win-back discount config was delivered in B2; **renewal-prompt messaging + cancellation-handling settings** config fields are not yet added (minor).

## B5 · Access control & logging — **PARTIAL ◑**
**Done (via B1/B4):**
- **Admin-only** access enforced on all admin membership controllers (`ADMIN_RESOURCE = Ahy_CaliberNation::membership`, ACL resource added).
- **Activity logging** for `renewed` (B1), `status_change`/`expired` (B1 + admin `SetStatus`), including **`admin_user_id`** attribution on admin manual actions.

**Done (2026-07-10):**
- **Admin config-change logging** ✅ — `Observer/LogAdminConfigChange.php` on `admin_system_config_changed_section_caliber_nation` (registered in `etc/adminhtml/events.xml`) writes a `config_change` activity row: admin user id, scope, and the changed config paths. Skips no-op saves. Logs paths, not value diffs (event exposes paths only).

**Remaining:**
- **Member-only** feature-gating helper (reused across member-only features) — *dropped for now per decision; login-required account pages + service-layer guards already in place.*

## B6 · Landing revamp & FAQs
- Revamp `/caliber-nation` marketing content per updated scope; update **FAQs**.
- Savings section = placeholder (deferred with pricing).

## B7 · QA / demo / deploy
- Test renewal (success/fail/retry/expire), win-back auto-add + prompts, early-bird badges, admin grid + manual actions, access control, logging.
- Validate secure token handling + role-based access.
- Demo, staging/dev1, in-scope revisions, **test-data cleanup**, prod deploy, **validate the renewal scheduler** (cron) in prod.

---

## 3. Verification (per workstream)
- **B0:** after a signup order, `payment_token_id` is populated; backfill fills existing members.
- **B1:** set a membership's `renewal_date` to today with a valid token → run cron → charged, `renewal_date` extended, `renewal_log` success, receipt email (in Mageplaza log), activity `renewed`. Force a decline → `renewal_pending`, retry rows, → `expired` after policy, group reverted.
- **B2:** expired member → membership auto-added to cart + renewal prompt shown at checkout/touchpoints.
- **B3:** flagged product shows early-access badge for members within the timing window.
- **B4:** admin grid lists/filters/overrides; charge-now renews; config changes persist.
- **B5:** non-admins blocked from management; non-members blocked from member features; renewals + admin changes logged.
- **B7:** full regression on dev1; renewal cron validated in prod.

## 4. Decisions (resolved) & remaining input
Resolved:
- ✅ **Renewal retry:** 3 attempts / 7 days (day 0 → 3 → 7), then `expired`.
- ✅ **Renewal order:** ~~create a Magento order per renewal~~ → **reversed 2026-07-10**: no order; renewal trail = renewal_log + activity_log + email + gateway (see B1).
- ✅ **Day-31 win-back discount:** BUILD in B2, **admin-configurable** (enabled, days_threshold, discount_type fixed/percent, discount_value).
- ✅ **Early access (B3):** deferred.

Still needed from business (does NOT block B0/B1):
- **Pre-renewal notice / compliance:** spec says "no reminder emails prior to renewal," but some US state auto-renewal laws require advance notice before charging an annual plan. Confirm the no-reminder rule is legally signed off, or we add a pre-renewal notice.
- **Dunning:** send a payment-failed email on a failed renewal attempt and/or on expiry? (yes/no)

## 5. Build sequence & status
1. ✅ **B0** (token linkage)
2. ✅ **B1** (renewal engine, verified live; renewal order reversed — no Magento order)
3. ✅ **B2** (win-back + Day-31 discount, verified live) — *remaining: move auto-add to `checkout_cart_load_after`*
4. ✅ **B4** (admin grid + actions) — *remaining: renewal-prompt / cancellation messaging config*
5. ◑ **B5** (admin-only ACL + admin logging done; member-gating + config-change logging remaining)
6. ⬜ **B3** (early access) — deferred
7. ⬜ **B6 + B7** (landing revamp + FAQs + QA/deploy)

## 6. Reused assets
Authorize.Net CIM (`AuthorizeNetApi`, `CustomerProfileCreator`, `ahy_authorizenet_customer_profile`, `vault_payment_token`) · cron pattern (`Ahy_Authorizenet` crontab) · `ActivateMembership` / `MembershipManagementService` / `Config` / `ActivityLogger` / `MembershipRepository` · Mageplaza SMTP for emails.
