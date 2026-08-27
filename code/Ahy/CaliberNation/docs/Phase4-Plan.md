# Caliber Nation — Phase 4 Plan (Emails & Messaging)

> **Status:** Planning. Phases 1–3B complete (membership spine, onboarding, renewal/win-back, admin, pricing/savings engine, PDP/account/header/PLP display). Phase 4 = the **emails & messaging** layer that surfaces member pricing/savings to customers and gives admins control of the copy.
>
> **Scope (this phase):**
> 1. Member pricing/savings in **order & subscription emails**
> 2. **Cancellation email**
> 3. **"Average savings" / comparison messaging** blocks
> 4. Admin config for **renewal-prompt / savings / early-bird messaging**
> 5. Admin config for **cancellation-handling settings**
>
> **Not in this phase (moved to a later phase):** early-access feature (flags/timing/badges), Klevu search-results pricing, seller import, affiliate tracking, landing revamp + FAQs. *(These were previously loosely called "Phase 4"; this phase is now the Emails & Messaging scope.)*
>
> **No new tables** — reuses `ahy_caliber_nation_order_savings`, `ahy_caliber_nation_membership.lifetime_savings`, `activity_log`, and admin config.
>
> **BUILD STATUS (2026-07-16):** ✅ **P4.5** (cancellation config + paid-through lifecycle + cron sweep) · ✅ **P4.4** (messaging config) · ✅ **P4.1** (`MembershipEmailService`, order-email savings totals line, welcome/renewal savings vars) · ✅ **P4.2** (cancellation email, toggle-gated, both cancel paths) · ✅ **P4.3** (`AverageSavings` ViewModel + landing block). Lint/XML/DI/functional verified. **Live-only checks remaining:** email rendering (welcome/renewal/cancellation/order savings — via SMTP Email Logs), the cancel→email→paid-through flow, and the cron expiry sweep. Per-line savings *in the order email* deferred (needs an items-template override; the order-total savings line is built).

---

## 0. Requirement → workstream mapping
| Requirement | Workstream |
|---|---|
| Display member pricing in order and subscription emails | **P4.1** |
| Setup caliber member cancellation email (onboarding + renewal exist) | **P4.2** |
| Display "Average savings" & comparison messaging wherever required | **P4.3** |
| Configure renewal prompts, savings messaging, early-bird messaging | **P4.4** |
| Configure cancellation handling settings | **P4.5** |

---

## 1. Locked decisions (2026-07-16)
1. **Order-email savings — BOTH per-line and total.** Each qualifying line shows "you saved $Y", plus an order-level "As a Caliber Nation member you saved $X on this order." Shown only when the order has member savings > 0.
2. **"Average savings" basis — per member order** (Σ `order_savings.savings` ÷ member-order count), cached, with a min-order-count guard; **fall back to an admin-entered static value** when there isn't enough data. Store-wide figure (cache-safe).
3. **Cancellation timing — PAID-THROUGH.** On cancel: status → `cancelled`, **auto-renew off** (no further charge), member **keeps benefits until `renewal_date`**, and the customer group is reverted **at expiry (renewal_date passes)**, not immediately. No refund (membership is no-refund). Email states the benefits-until date.
4. **Cancellation email — admin-toggleable** (`cancellation/send_email`, default **Yes**). Order-email savings line is always on when savings > 0 (not toggled).

---

## 2. Workstreams

### P4.1 · Member pricing/savings in order & subscription emails
- **Order confirmation email:** add a "member savings" block to the transactional order email that shows the order's savings from `ahy_caliber_nation_order_savings` (total, optionally per line). Implement as a `ViewModel`/`Block` injected via the `sales_email_order_items` layout handle (and its guest/invoice variants), rendered only when the order has member savings > 0. Transactional email → no FPC concerns.
- **Subscription emails (welcome, renewal):** extend `welcome.html` + `renewal.html` with a savings/benefit line (e.g. lifetime savings to date, "members save on every order"). Pass the values via `setTemplateVars` where the emails are sent (`ActivateMembership`, `RenewMembership`).
- **Refactor (optional, recommended):** consolidate the TransportBuilder logic (currently duplicated across `ActivateMembership` / `RenewMembership` / `MembershipSignupService`) into a `Model/Service/MembershipEmailService` so welcome/renewal/cancellation all send consistently. Reduces the P4.2 work too.

### P4.2 · Cancellation email
- New template `caliber_nation_cancellation` (`view/frontend/email/cancellation.html`) + register in `etc/email_templates.xml`.
- Send it from **both** cancel paths: `MembershipManagementService::cancelMembership` (self-service) and the admin `Controller/Adminhtml/Membership/Cancel`. Use the P4.1 `MembershipEmailService`.
- Content: confirmation, **effective end date** (per decision #3), lifetime savings, and a **re-join / win-back** CTA. Log an activity entry (reuse `ACTION_CANCELLED` detail, or note email sent).
- Gated by `cancellation/send_email` config (P4.5).

### P4.3 · "Average savings" / comparison messaging blocks
- `ViewModel/AverageSavings` — computes the average (decision #2) from `order_savings` with per-request caching + a min-data fallback to the admin static value; exposes formatted average + comparison text.
- Reusable template/block: "Members save an average of $X" + regular-vs-member comparison copy. Placement: **landing page** and **PDP** (reusable on CTAs/account). Copy is admin-configurable (P4.4).
- Cache-safe: the average is store-wide (not per-customer), so it's fine on FPC pages.

### P4.4 · Admin config — messaging (renewal / savings / early-bird)
- New `system.xml` group **`messaging`** under `caliber_nation`: renewal-prompt heading/body, savings-messaging text, average-savings intro text, early-bird messaging text. `Config.php` getters.
- Wire into: `ViewModel/RenewalPrompt` (renewal copy), `AverageSavings`/savings blocks (savings copy). Early-bird text field is prepped now so it's ready when the early-access feature lands in a later phase.

### P4.5 · Admin config — cancellation-handling settings
- New `system.xml` group **`cancellation`**: `send_email` (yes/no, default Yes), `confirmation_message` (text shown on self-service cancel). Timing is fixed to **paid-through** (locked decision #3).
- `Config.php` getters; wire into `cancelMembership` (email gate + confirmation copy) and the My-Account cancellation UI.
- **Paid-through lifecycle handling (important):** on cancel, `cancelMembership` sets `status=cancelled` + `auto_renew=0` but must **NOT** revert the customer group immediately (member keeps benefits until `renewal_date`). Because the renewal cron only processes `active|renewal_pending` + `auto_renew=1`, cancelled members would never lose their group at expiry — so extend the cron (or add a small daily sweep) to find **`status=cancelled` AND `renewal_date <= now`**, revert the member group, and mark them `expired`. Verify the current `cancelMembership` isn't reverting the group on cancel today; adjust if it is.

---

## 3. Config / data additions
- **Config groups:** `caliber_nation/messaging/*`, `caliber_nation/cancellation/*` (+ defaults in `config.xml`, ACL already covers the section).
- **Email template:** `caliber_nation_cancellation`.
- **New service:** `Model/Service/MembershipEmailService` (send welcome / renewal / cancellation with shared vars incl. savings).
- **New ViewModel:** `AverageSavings`. **Reused:** `order_savings`, `lifetime_savings`, `RenewalPrompt`.
- **No DB schema changes.**

---

## 4. Build sequence
1. **P4.5 + P4.4** — admin config groups first (messaging + cancellation), so downstream pieces read real settings.
2. **P4.1 refactor** — `MembershipEmailService`; add savings vars to welcome/renewal; order-email savings block.
3. **P4.2** — cancellation email (uses the service + config).
4. **P4.3** — average-savings ViewModel + messaging blocks (uses messaging config).
5. Verify + QA.

---

## 5. Acceptance criteria
- **P4.1:** a member order confirmation email shows the correct savings (matches `order_savings`); welcome/renewal emails show the savings/benefit line; non-member / zero-savings orders show no savings block.
- **P4.2:** cancelling (self-service and admin) sends the cancellation email with the correct effective date + re-join CTA, only when `send_email` is on; logged.
- **P4.3:** landing/PDP show "Members save an average of $X" using the configured basis + admin copy; store-wide value, cache-safe.
- **P4.4:** editing messaging config changes the renewal prompt / savings copy on the storefront; a `config_change` audit row is written (existing B5 logging).
- **P4.5:** cancellation timing + email toggle behave per config; confirmation copy shows on self-service cancel.
- Emails remain **disabled on local** (verify via Mageplaza SMTP Email Logs, not delivery).

---

## 6. Risks / notes
- **Order-email injection** — Magento order emails render via `Sales\Block\Order\Email\Items`; the savings block must attach to the right email layout handle and tolerate guest orders. Verify against the theme's transactional email templates.
- **Average-savings performance** — aggregate query must be cached (per request + optionally a short TTL cache) so it doesn't run per PDP render.
- **Local email testing** — all sends are logged, not delivered (project constraint). Verify template rendering via the Mageplaza Email Log.
