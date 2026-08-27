# Caliber Nation — Feature-by-Feature Demo Guide

> Each requested line is followed by the right treatment: **schema** (show it), **what was done** (explain), or **demo/test steps** (do it live).
> Demo account: `lesos62307@suahi.com` · Product: `aditya-simple-product` (`aditya-simple-30`, $30, seller *Aditya Shahapurkar* #17102 → **5% member = $28.50**).
> Status: **✅ built** · **◑ partial** · **🚧 roadmap**.

---

# A. Planning & Architecture

### Prepare questionnaire & finalize detailed requirement documentation based on approved features
**Show:** `app/code/Ahy/CaliberNation/docs/` — Phase1-Foundation, Phase2A/2B, Phase3/3B, Phase4, Phase5 plans + TestCases + Pending-Implementation. Narrate: every phase was spec'd, questioned, and signed off before build.

### Design DB schema for membership, subscription lifecycle, renewal, incentive tracking, savings tracking
**Show the tables (`etc/db_schema.xml`) with schema:**

- **`ahy_caliber_nation_membership`** — one row per customer (the subscription entity):
  `entity_id`, `customer_id` (UNIQUE, FK→`customer_entity`, ON DELETE CASCADE), `status` (active|renewal_pending|expired|cancelled), `tier` (annual), `start_date`, `renewal_date`, `auto_renew`, `payment_token_id` (FK→`vault_payment_token`, ON DELETE SET NULL), `is_trial`, `lifetime_savings`, `renewal_reminder_sent_at`, `winback_email_sent_at`, `created_at`, `updated_at`. Indexes on `status`, `renewal_date`.
- **`ahy_caliber_nation_renewal_log`** — one row per renewal attempt: `membership_id`, `customer_id`, `result` (success|failed), `amount`, `payment_token_id`, `transaction_id`, `order_id`, `error_message`. Indexes on `membership_id`, `result`.
- **`ahy_caliber_nation_order_savings`** — per-line savings ledger: `order_id`, `order_item_id`, `customer_id`, `product_id`, `seller_id`, `regular_price`, `member_price`, `paid_price`, `savings`, `applied_rules` (JSON). Indexes on `customer_id`, `order_id`.
- **`ahy_caliber_nation_seller_participation`** — seller opt-in: `seller_id` (UNIQUE), `is_enabled`, `discount_type` (percent|fixed), `discount_value`.
- **`ahy_caliber_nation_member_price_rule`** — product/category rules: `scope` (category|product), `target_id`, `discount_type`, `discount_value`, `is_active`.
- **`ahy_caliber_nation_activity_log`** — audit: `customer_id`, `admin_user_id`, `action`, `detail`, `order_id`.

### Define tables, relationships, indexes, constraints for membership, renewal status, trial handling
**Explain what was done:** current-state in `membership`; append-only history in `renewal_log` / `activity_log`. Referential integrity via FKs (customer CASCADE, token SET NULL). Query paths indexed (`status`, `renewal_date` power the cron sweeps). Trial handled by `is_trial` + config (`membership/trial_enabled`, `trial_months`) → duration = 12 + bonus months.

### Define price priority between seller override, member price, and regular price
**Explain what was done:** resolution order = **seller participation discount → member base discount (config) / product-category rules → regular price**, with a **global cap** (`pricing/cap_value = 50%`). Most-specific layer wins; the resolver never lets the member price fall below the cap. (Demo: seller #17102's 5% is why `aditya-simple` is $28.50, not the config base.)

### Define stacking behavior for membership product with promotional cart rules
**Explain what was done:** member price is written as the item's **base price** in `sales_quote_collect_totals_before` (via `ApplyMemberPrice` → `setCustomPrice`), which runs **before** Magento's cart-rule/discount collector. So native **cart price rules and coupons stack on top** of the member price rather than competing with it. The membership product itself is excluded from member discounting (it has its own price / win-back handling).

### Define seller opt-in model and Caliber pricing structure
**Explain what was done:** admin-managed opt-in per seller (`seller_participation` table + **Caliber Nation → Seller Participation** grid). Each participating seller sets a `percent|fixed` Caliber discount applied to their catalog for active members. Non-participating sellers show regular price.

### Define cancellation flow and renewal-confirmation handling as per US subscription requirements
**Explain what was done:** self-service cancel from My Account → `cancelled` + auto-renew off, **paid-through** (benefits kept until `renewal_date`, then cron expires). Auto-renew requires an explicit saved-card confirmation; **advance renewal reminder** email fires N days before billing (`emails/renewal_reminder_days`); cancellation confirmation message configurable. *(◑ full US advance-notice wording is partial.)*

---

# B. Setup

### Git and GitHub repository setup
**Show:** `git remote -v`, branch `local-development`, `git log --oneline -5` (latest: master-switch/header-savings/paid-through commit).

### Create a separate module with dependencies and folder structure
**Show:** `Ahy_CaliberNation` — `registration.php`, `etc/module.xml` (`<sequence>`: Catalog, Customer, Sales, Quote, Vault, Payment, Config, Ahy_Authorizenet), `composer.json`, and the `Api / Model / Observer / Plugin / Cron / ViewModel / Magewire / CustomerData / Controller / etc / view` tree. Independent of legacy `Ahy_Caliber`.

---

# C. Backend / Core logic

### Create membership entity and link to customer account ✅
**Demo/test steps:**
1. Note `lesos62307@suahi.com` has no membership yet (`SELECT * FROM ahy_caliber_nation_membership WHERE customer_id=<id>`).
2. Complete a membership purchase (Act in section G) → row created with `customer_id` = that account, `status=active`.
3. Show the UNIQUE `customer_id` constraint (one membership per customer) and that deleting the customer cascades.

### Implement annual subscription logic with trial handling ✅
**Demo/test steps:** after signup, show `start_date` = now and `renewal_date` = +12 months. To show trial: Admin → Config → **Membership → Trial** = Yes / 1 month → new signup gets `is_trial=1` and duration +1 month.

### Implement status transitions: Active, Expired, Renewal Pending, Cancelled ✅
**Demo/test steps:** use `set_state.php <email> <status>` (or Admin → Memberships → Set Status) to walk the account through each state and reload `/caliber-nation/account` to show the panel/badge for each. Explain the flow: Active→(fail)→Renewal Pending→(retries out)→Expired; Active→(cancel)→Cancelled→(term ends)→Expired.

### Prepare APIs for membership discount rules; ensure correct data flow to frontend ✅
**Demo/test steps:** hit `GET /caliber-nation/pricing/memberprice?ids=<productId>` as a logged-in member → JSON with member prices; as guest → `pricing_enabled` + no prices. This is what the Klevu PLP JS calls to inject prices after render.

### Implement cron-based renewal execution logic ✅
**Demo/test steps:** `bin/magento cron:run --group=default` (or run `Cron\ProcessRenewals::execute()`). It sweeps `auto_renew=1` memberships with `renewal_date <= now` in states active/renewal_pending, plus reminder/win-back/expiry sweeps. Show `ahy_caliber_nation_renewal_log` rows after.

### Extend membership only after successful renewal payment ✅
**Explain + demo:** `RenewMembership::handleSuccess` sets the new `renewal_date` **only** after the gateway confirms the charge; on failure the date is untouched. Show a success log row (has `transaction_id`) vs. a failed one (has `error_message`).

### Setup service layer for auto-renew ON/OFF toggle ✅
**Demo/test steps:** My Account → Caliber Membership → flip **Auto-Renew**. Enabling with no bound card opens the **card picker**; can't turn on without a usable card (card⟺auto-renew invariant). Show `membership.auto_renew` + `payment_token_id` change.

### Implement session-based routing to signup/login from Caliber membership page ✅
**Demo/test steps:** as guest, open `/caliber-nation` → "Join" routes to the signup form; as a logged-in non-member it skips account creation; as an active member it routes to the account/overview. (Account-first flow.)

### Conflict resolution for discounts — stacking promotions + correct pricing ✅
**Demo/test steps:** add `aditya-simple` as a member ($28.50), then apply a cart-rule coupon → coupon discount stacks on the $28.50 base. Show totals reflect both layers, capped at 50%.

### Implement self-service cancellation from My Account ✅
**Demo/test steps:** Caliber Membership → **Cancel Membership** → status `cancelled`, auto-renew off, confirmation message shown; benefits remain until `renewal_date` (paid-through).

### Reuse SavedCC payment method (tokenization) for auto-renew ✅
**Explain:** the card saved at checkout is vaulted (`vault_payment_token`) and its id stored on `membership.payment_token_id`; the renewal cron charges that token. **Demo:** show the bound card on the account page ("Renewal card: VISA ••1111").

### Implement automatic debit via saved card ✅
**Demo/test steps:** with `renewal_date <= now` + auto-renew on + a valid token, run the cron → a renewal charge is attempted on the saved token; `renewal_log` records success + `transaction_id`.

### Handle renewal failures and status transitions ✅
**Explain:** failed charge → `renewal_pending`; after **3 attempts or 7 days** → `expired` (group reverted, expiry email). Demo by pointing at `renewal_log` failed rows and the resulting status.

### Disable non-credit-card payments when membership is in the cart ✅
**Demo/test steps:** add the membership to cart → checkout shows **only** card/vault methods; other methods (offline, PayPal, etc.) are hidden.

### Configure membership as virtual product with no tax class ✅
**Demo/test steps:** show the `caliber-nation-annual` product = **Virtual**, tax class **None**; at checkout the membership line shows **$0 tax**.

### Allow membership purchase with physical products ◑
**Demo/test steps:** add membership + `aditya-simple` (physical) together → both check out in one order; card-only rule applies but mixing is allowed. *(Re-verify end-to-end with a placed mixed order.)*

### Auto-create account for guest membership purchase and link membership ✅
**Demo/test steps:** buy the membership as a **guest** with `lesos62307@suahi.com` → after placement an account is auto-created, logged in, and the membership row is linked to it (group 6).

### Ensure compatibility with affiliate tracking systems 🚧
**Roadmap:** attribution on the custom membership flow + renewal-commission policy not yet built. Mention as planned.

---

# D. Pricing, savings & search

### Implement dynamic member pricing logic ✅
**Demo/test steps:** as member, `aditya-simple` shows $28.50 everywhere; as guest, $30. Toggle Config → Member Pricing off → reverts to $30 (proves it's dynamic/config-driven).

### Implement seller-specific Caliber pricing support ✅
**Demo/test steps:** Admin → Seller Participation → seller #17102 = 5%. Change to 10% → `aditya-simple` member price becomes $27. Show a non-participating seller's product stays at regular price.

### Apply member pricing in cart, checkout totals ✅
**Demo/test steps:** add `aditya-simple` → cart + checkout totals use $28.50 (not $30). Show the line + subtotal.

### Display member pricing in order & subscription emails ◑
**Demo:** Email Log → welcome/renewal emails include savings vars + order-email **total** savings line. *(Per-line savings in the order email deferred.)*

### Setup member onboarding, renewal, cancellation, etc. emails ✅
**Demo/test steps:** trigger each (signup / cron reminder / cancel) → view rendered templates in **Mageplaza → Email Log** (delivery disabled on local): welcome, renewal-reminder, renewal-receipt, expiry, win-back, cancellation.

### Display member pricing in search results ✅
**Demo/test steps:** search a term returning `aditya-simple` → Klevu result card shows the member price for logged-in members (fed by the memberprice API).

### Ensure membership product supports stacking with cart rules ✅
**Demo:** same as "conflict resolution" — coupon stacks on member base price.

### Calculate and store savings per order ✅
**Demo/test steps:** place a member order with `aditya-simple` → `ahy_caliber_nation_order_savings` gets a row (`regular_price`, `member_price`, `paid_price`, `savings`, `applied_rules`).

### Maintain cumulative member savings data ✅
**Demo/test steps:** after the order, `membership.lifetime_savings` increments by the order's savings; the header pill reflects the new total.

### Handle Klevu templates to display dynamic discounted prices ✅
**Explain/demo:** Klevu PLP JS injection calls the memberprice endpoint and rewrites the price on rendered cards for members.

### Implement necessary filtering related to Caliber Nation membership 🚧
**Roadmap:** scope to finalize; not built. Mention as planned.

---

# E. Expired members, win-back & early access

### Identify expired members across platform ✅
**Explain/demo:** `ExpiredMemberLocator` + the `status` index find expired/lapsed members; used by cron sweeps + storefront prompts.

### Automatically add membership product to cart for expired members ✅
**Demo/test steps:** `set_state ... expired` → log in as `lesos` → the membership is auto-added to the cart on login.

### Implement Day-31 renewal discounted pricing logic ✅
**Demo/test steps:** with the member expired within the 30-day window, the auto-added membership shows the **win-back price $79.99** (20% off $99.99).

### Prompt expired members to renew during checkout ◑
**Demo/test steps:** as the expired member, open cart/checkout → renewal prompt appears. *(Only cart/checkout touchpoint today; account/PDP/header prompts pending.)*

### Create early-access flags for products/sellers/customers 🚧
**Roadmap:** not built.

### Implement admin-configurable early-bird timing and messaging 🚧
**Roadmap:** early-bird **message** field exists in admin config; timing engine not built.

---

# F. Middleware & logging

### Middlewares for admin-only access of membership management ✅
**Demo/test steps:** the Caliber Nation admin menus/controllers are behind ACL (`Ahy_CaliberNation::config` / management resources). Log in as an admin role without the resource → menus/actions denied.

### Middlewares restricting membership-specific features for customers ✅
**Demo/test steps:** member-only surfaces gate on `MemberAccess`/status — as a guest/non-member the header pill, member prices, and account panel don't appear; as a member they do. The **master switch** off hides everything.

### Log membership activities (creation, renewal, cancellation, discount usage) ✅
**Demo/test steps:** perform create/cancel/renew → `ahy_caliber_nation_activity_log` rows with `action` + `detail`.

### Log admin updates to membership pricing / incentive configuration ✅
**Demo/test steps:** change a config value (e.g. price) in admin → an `activity_log` row with `admin_user_id` + `action=config_change`.

---

# G. Storefront / frontend

### Display regular vs Caliber price on product listing pages ✅
**Demo/test steps:** member browses a category → `aditya-simple` shows **$30 struck-through / $28.50 member**.

### Display price comparison and savings on product detail pages ✅
**Demo/test steps:** open the PDP as a member → regular vs member price + "you save $1.50" line.

### Display member savings and renewal prompts ✅ / ◑
**Demo/test steps:** member sees savings messaging; expired member sees renewal prompt (cart/checkout ◑).

### Show cumulative savings in header for active members ✅
**Demo/test steps:** as a member with recorded savings, the pre-header bar shows **"You've saved $X as a member."** (FPC-safe customer-data section; log out/in once if the cached section is stale.)

### Display membership status, renewal date, savings, payment method, auto-renew status ✅
**Demo/test steps:** `/caliber-nation/account` → the membership card shows all five + the auto-renew toggle.

### Implement auto-renew ON/OFF toggle ✅
**Demo:** flip it; show the card picker / change-card flow.

### Implement easy cancellation interface ✅
**Demo:** the Cancel Membership button + confirmation panel.

### Display "Average savings" & comparison messaging where required ✅
**Demo/test steps:** `/caliber-nation` shows **"Members save an average of $50"** (admin static value; hides at $0; will use real order data as it accrues).

### Implement membership signup via dedicated landing page ✅
**Demo:** the whole `/caliber-nation` signup flow (section for `lesos`).

### Enable membership signup during cart and checkout ◑/🚧
**Roadmap/partial:** CTAs/layout hooks exist; full inline signup during cart/checkout not finished. Mention as planned.

### Enable membership signup during account registration ✅
**Demo/test steps:** go to the account **registration** page → "Join & Save" opens the signup **modal** (blurred backdrop) with the same flow.

### Implement renewal popups for expired members across touchpoints ◑
**Demo:** cart/checkout prompt today; other touchpoints pending.

### Display early-bird badges on eligible products 🚧
**Roadmap:** not built.

### Revamp Caliber membership landing page as per updated scope 🚧
**Roadmap:** current landing page is functional; full revamp pending.

### Update FAQs and savings section 🚧
**Roadmap:** pending.

---

# H. Admin

### Create admin membership management grid (status, renewal, cancellation) ✅
**Demo/test steps:** Admin → **Caliber Nation → Memberships** → columns for status/renewal/cancel; row actions **Set Status / Charge Now / Cancel**. Use it to change `lesos`'s status live.

### Configure membership pricing from admin ✅
**Demo:** Config → **Membership → Annual Membership Price** ($99.99).

### Configure discounted renewal pricing after 30 days ✅
**Demo:** Config → **Win-back Discount** → 20% / 30-day threshold.

### Manage seller opt-in and Caliber pricing ✅
**Demo:** **Caliber Nation → Seller Participation** grid (seller #17102 = 5%).

### Adjust import process to support Caliber participation and pricing 🚧
**Roadmap:** member-discount columns in the product import not yet added.

### Configure renewal prompts, savings messaging, early-bird messaging ✅
**Demo:** Config → **Messaging** (renewal prompt heading/body, savings message, average-savings intro/static, early-bird message).

### Configure optional trial period settings ✅
**Demo:** Config → **Membership → Trial** (enable + months).

### Configure cancellation handling settings ✅
**Demo:** Config → **Cancellation Handling** (send email, confirmation message) + **Lifecycle Emails** toggles.

---

## Quick reference
- Landing `/caliber-nation` · Account `/caliber-nation/account` · Admin menu **Caliber Nation** · Config **Stores → Configuration → Caliber Nation**.
- Emails: **Mageplaza → Email Log** (never delivered on local).
- State helper: `php <scratchpad>/set_state.php lesos62307@suahi.com <status>`.
- Cache: `/Applications/MAMP/bin/php/php8.1.13/bin/php bin/magento cache:flush`.
