# Caliber Nation — Phase 2 Plan (Comprehensive, Updated)

> **Status:** 2A engine built & verified on dev DB; landing-page realignment + entry points remaining. 2B not started.
> **Purchase model (resolved):** membership is a **virtual product bought through the cart → checkout → order** (never charged inline). One order = one activation.
> **Landing model (resolved — ACCOUNT-FIRST):** `/caliber-nation` is a marketing page that keeps a **sign-up form for ACCOUNT CREATION only** (no card fields). A guest must create an account (or log in) there first; then the membership is added to the cart and paid for at **checkout**.
> **Scope:** Phase 2 = the whole membership build **EXCEPT** (1) member/seller **pricing + savings** and (2) **affiliate tracking**, which are deferred.
> **Split:** **2A** = get members in (onboarding + order purchase + management). **2B** = sustain them (renewal, win-back, early access, admin, ops).

## Resolved decisions
- ❌ **OTP removed** · ❌ **REST API removed** (payment-bypass risk) · **Tiers = Annual only**.
- **Activity log** = dedicated table `ahy_caliber_nation_activity_log`.
- **Member status** = dedicated customer group "Caliber Nation Member" (id resolved into config).
- **Landing = account-first**, no inline payment; payment at standard checkout.
- **New guests must create an account before purchase** (no pure-guest membership checkout); the guest auto-create observer remains only as a safety net.
- **Auto-login after signup** = yes (so the user proceeds to checkout logged-in).

---

## 1. Requirement → bucket mapping (nothing omitted)

Legend: **2A** / **2B** = sub-phase · **EXC** = excluded (pricing/savings or affiliate) · **PART** = split · ✅ = built.

### Discovery & design
| # | Requirement | Bucket |
|---|---|---|
| 1 | Prepare questionnaire + finalize requirement documentation | 2A |
| 2 | DB schema: membership, lifecycle, renewal, incentive, savings | PART (membership/lifecycle ✅; renewal 2B; **savings EXC**) |
| 3 | Tables/relationships/indexes/constraints: membership, renewal status, trial | 2A ✅ / 2B (renewal) |
| 4 | Price priority (seller override / member / regular) | **EXC** |
| 5 | Stacking behavior of membership product w/ promo cart rules | **EXC** |
| 6 | Seller opt-in model + Caliber pricing structure | **EXC** |
| 7 | Cancellation flow + renewal confirmation handling (US law) | PART (cancellation 2A ✅; renewal confirmation 2B) |

### Setup & backend
| # | Requirement | Bucket |
|---|---|---|
| 8 | Git/GitHub repo setup | 2A ✅ |
| 9 | Separate module + dependencies + folder structure | 2A ✅ |
| 10 | Membership entity + link to customer | 2A ✅ |
| 11 | Annual subscription + trial handling | 2A ✅ |
| 12 | Status transitions Active/Expired/Renewal Pending/Cancelled | PART (Active/Cancelled 2A ✅; Expired/Renewal Pending 2B) |
| 13 | APIs for membership data flow to frontend | PART (data-flow 2A; **discount-rule APIs EXC**) |
| 14 | Cron-based renewal execution | 2B |
| 15 | Extend membership only after successful renewal payment | 2B |
| 16 | Auto-renew ON/OFF toggle service | 2A ✅ |
| 17 | Session-based routing to signup/login | 2A ✅ |
| 18 | Conflict resolution for discounts / stacking pricing | **EXC** |
| 19 | Self-service cancellation from My Account | 2A ✅ |
| 20 | Reuse SavedCC tokenization | 2A ✅ (store) / 2B (use for renewal) |
| 21 | Automatic debit via saved card | 2B |
| 22 | Handle renewal failures + status transitions | 2B |
| 23 | Disable non-credit-card payments when membership in cart | 2A ✅ |
| 24 | Membership as virtual product, no tax class | 2A ✅ |
| 25 | Allow membership purchase with physical products | 2A ✅ |
| 26 | Auto-create account for guest purchase + link | 2A ✅ (safety net; account-first is primary) |
| 27 | Affiliate tracking compatibility | **EXC** |
| 28 | Dynamic member pricing logic | **EXC** |
| 29 | Seller-specific Caliber pricing | **EXC** |
| 30 | Apply member pricing in cart/checkout totals | **EXC** |
| 31 | Member pricing in order/subscription emails | **EXC** |
| 32 | Onboarding / renewal / cancellation emails | PART (onboarding+cancellation 2A; renewal 2B) |
| 33 | Member pricing in search results | **EXC** |
| 34 | Membership product supports stacking with cart rules | **EXC** (basic cart compat 2A ✅) |
| 35 | Calculate & store savings per order | **EXC** |
| 36 | Maintain cumulative savings | **EXC** |
| 37 | Klevu templates for discounted prices | **EXC** |
| 38 | Membership-related filtering | 2A |
| 39 | Identify expired members | 2B |
| 40 | Auto-add membership product to cart for expired members | 2B |
| 41 | Day-31 renewal discounted pricing | **EXC** (win-back mechanics 2B; discount deferred) |
| 42 | Prompt expired members to renew during checkout | 2B |
| 43 | Early-access flags for products/sellers/customers | 2B |
| 44 | Admin-configurable early-bird timing & messaging | 2B |
| 45 | Middleware: admin-only membership management | 2B |
| 46 | Middleware: restrict member features to members | 2A (basic) / 2B |
| 47 | Log membership activities | PART (create/cancel 2A ✅; renew 2B) |
| 48 | Log admin updates to pricing/incentive config | 2B |

### Frontend
| # | Requirement | Bucket |
|---|---|---|
| 49 | Regular vs Caliber price on PLP | **EXC** |
| 50 | Price comparison & savings on PDP | **EXC** |
| 51 | Member savings + renewal prompts | PART (**savings EXC**; renewal prompts 2B) |
| 52 | Cumulative savings in header | **EXC** |
| 53 | Account: status/renewal/savings/payment/auto-renew | PART (all 2A ✅ except **savings EXC**) |
| 54 | Auto-renew ON/OFF toggle UI | 2A ✅ |
| 55 | Easy cancellation interface | 2A ✅ |
| 56 | "Average savings" & comparison messaging | **EXC** |
| 57 | Signup via dedicated landing page | 2A (rework to account-first — see §3) |
| 58 | Signup during cart & checkout | 2A (entry-point CTAs remaining) |
| 59 | Signup during account registration | 2A (remaining) |
| 60 | Renewal popups across touchpoints | 2B |
| 61 | Early-bird badges on eligible products | 2B |
| 62 | Revamp Caliber landing page | 2B (account-first CTA in 2A) |
| 63 | Update FAQs + savings section | PART (FAQs 2B; **savings section EXC**) |

### Admin
| # | Requirement | Bucket |
|---|---|---|
| 64 | Membership management grid (status/renewal/cancel/override) | 2B |
| 65 | Configure membership price | 2A ✅ |
| 66 | Configure discounted renewal pricing after 30 days | **EXC** (config stub 2B) |
| 67 | Manage seller opt-in + Caliber pricing | **EXC** |
| 68 | Adjust import for Caliber pricing | **EXC** |
| 69 | Configure renewal / savings / early-bird messaging | PART (renewal + early-bird 2B; **savings EXC**) |
| 70 | Optional trial period settings | 2A ✅ |
| 71 | Cancellation handling settings | 2A |

### Demo / QA / deploy
| # | Requirement | Bucket |
|---|---|---|
| 72 | Demo revamp to client | 2A & 2B |
| 73 | Provide features on staging/dev1 | 2A & 2B |
| 74 | Implement in-scope revisions | 2A & 2B |
| 75 | Cleanup test data | 2B |
| 76 | Test signup/renewal/cancellation/win-back | PART (signup/cancel 2A; renewal/win-back 2B) |
| 77 | Validate member pricing across catalog/cart/checkout | **EXC** |
| 78 | Validate seller pricing + stacking | **EXC** |
| 79 | Validate secure token handling + role-based access | 2A (token ✅) / 2B (roles) |
| 80 | Deploy to prod; validate renewal scheduler (+ pricing) | PART (scheduler 2B; **pricing EXC**) |

---

## 2. Architecture — order-based, account-first, single activation

```
Entry point (landing / cart / checkout / registration)
      │  guest must create an account (or log in) first  →  logged-in
      ↓
Membership VIRTUAL product (tax class None) added to CART  (± physical products)
      ↓
Standard CHECKOUT — only credit-card / vault methods offered, $0 tax on membership line
      ↓
Order placed → checkout_submit_all_after
      ↓
ActivateOnOrderPlace observer → ActivateMembership (SINGLE activation point):
   • membership row → Active, renewal date        • assign "Caliber Nation Member" group
   • store vault token (auto-renewal)             • log activity        • welcome email
```

**Design rule:** no matter the entry point, a Magento order is placed and one observer activates the membership. Payment lives only in checkout.

---

## 3. PHASE 2A — Onboarding & Membership (account-first)

### A1 · Order-purchase engine — **BUILT & VERIFIED ✅**
- Membership **virtual product** `caliber-nation-annual`, **tax class None**, flagged `is_caliber_nation_membership` (data patches).
- **Member customer group** "Caliber Nation Member" + id in config.
- **`ActivateMembership`** service (single activation) + **`ActivateOnOrderPlace`** observer (`checkout_submit_all_after`).
- **Card-only** payment restriction observer (`payment_method_is_active`).
- **No-refund** credit-memo plugin.
- **Activity log** table + logger (create / cancel / auto-renew).
- **Guest auto-create** account safety net (`CustomerService::getOrCreateByEmail`).

### A2 · Landing page — account-first rework — **BUILT ✅ (verified live)**
- `/caliber-nation` keeps a **sign-up form for account creation** (name, email, password **+ billing address** — no card fields).
- `Magewire/MembershipSignup` reworked: payment Step 2 + success Step 3 **removed**; a single `join()` action → creates the account (guest) → **logs the customer in** (`loginById`) → **saves a default billing address** → **adds the membership product to the cart** → **redirects to checkout**.
- **Billing address collected at signup** (`CustomerService::createDefaultBillingAddress`) — required because the custom Ahy Hyva checkout does not collect billing for virtual carts. Shown for guests + logged-in customers with no default billing.
- Existing email → "log in to continue"; active member → "already a member".
- Template `membership-signup.phtml` rewritten to the account+address form; US state dropdown via `getUsRegions()`.
- **`MembershipOrderService` retired** (deleted) — no inline charge / programmatic order.

### A-fixes · Compatibility fixes found during live testing (all BUILT ✅)
End-to-end verified: account → billing → cart → card-only checkout → **completed order** → active member (group 6), `$0` tax.
- **Indexing:** membership product created via patch wasn't in the stock/price index (scheduled indexers) → not salable / $0 price. Patch now `reindexRow`s the product; setup requires `indexer:reindex cataloginventory_stock catalog_product_price`.
- **Free-gift promo:** cart rule **#185** ("Buy 1 product, get 1 Free Everest Decal", Amasty `ampromo_cart`) added a physical decal → cart non-virtual. Excluded the membership SKU via a rule condition (only fires when a non-membership product is present).
- **Hyva virtual-cart init:** `Plugin/HyvaCheckoutVirtualBillingFix` guards Hyva's `processBillingAddressForVirtualCart` crash for addressless customers.
- **Webkul virtual-order:** `Plugin/WebkulVirtualOrderShippingFix` skips `Webkul\MpMultiShipping` order-placed observer for shipping-less virtual orders (it did `strpos(null,…)`).
- **Legacy block:** removed the legacy `Ahy_Caliber` Yotpo "points" block from the My Account dashboard (it keys off group 6 and errored) via `customer_account_index.xml` `remove="true"` — legacy module untouched.

### A-known · Carried into 2B
- **Vault token not linked:** `ahy_caliber_nation_membership.payment_token_id` is empty because Authorize.Net vaults the card **after** `placeOrder()` (after our `checkout_submit_all_after` observer). The token exists; it just isn't linked. **First 2B task** (auto-renewal needs it).
- **Charge-before-validate** in `Ahy_Authorizenet` `PlaceOrderService` (flagged, not fixed) — failed orders can still charge.

### A3 · Entry-point CTAs — **PARTIALLY BUILT**
- ✅ **Cart** page + ✅ **account registration** page: "Join & Save" banner (`JoinCta` ViewModel + `cta/join-banner.phtml`, hidden for active members) → routes to the account-first landing → cart → checkout.
- ◑ **Checkout** CTA: deferred — the Hyva checkout is a Magewire SPA where standard layout blocks don't render in-flow; needs Hyva-checkout-specific placement (the cart CTA covers pre-checkout).
- ⬜ Product-page / header CTAs pair with member-pricing display → later.

### A4 · Entity, lifecycle & member group — **BUILT ✅** (extend)
- Entity + link ✅; **member group assign on activation** ✅; **revert on cancel** ✅; Active/Cancelled states ✅.
- Annual subscription + trial (bonus month) duration ✅.

### A5 · Cancellation, auto-renew, overview, emails — **BUILT ✅**
- Self-service cancel + auto-renew toggle ✅; My Account overview (status/renewal/payment/auto-renew) ✅; welcome email ✅; creation/cancellation logging ✅. (Savings excluded.)

### A6 · Routing verification — **REMAINING**
- Confirm bare `/caliber-nation` serves the CMS marketing page while `/caliber-nation/account` (+ any cart action) resolve as controllers. If shadowed, rename the module `frontName` (route id unchanged → layout handles unaffected).

### 2A verification (dev1)
- `/caliber-nation` shows marketing + **account-creation form** (no card fields).
- Guest signs up → account created → logged-in → membership in cart → **checkout** → pay (card-only, $0 tax) → membership **active**, in member group, token stored, welcome email, **order/receipt exists**.
- Existing email → login prompt. Active member → "already a member".
- Buy membership + a physical product → single order; physical item unaffected.
- Cancel → cancelled + group reverted + logged. No-refund enforced.

---

## 4. PHASE 2B — Renewal, Retention & Operations

- **B0 Vault-token linkage (FIRST):** link the vaulted card to `ahy_caliber_nation_membership.payment_token_id` after it is saved (post-`placeOrder`), and confirm the "Save this card" path always vaults. Prerequisite for renewal.
- **B1 Renewal engine:** cron auto-debit saved card; extend only on success; failure → `renewal_pending` → `expired`; `ahy_caliber_nation_renewal_log`; renewal receipt email; no pre-renewal reminders.
- **B2 Win-back:** identify expired; auto-add membership to cart; renewal prompts at checkout + popups across touchpoints. (Day-31 discount pricing deferred.)
- **B3 Early access / early bird:** flags on products/sellers/customers; admin timing + messaging; badges for members.
- **B4 Admin:** membership management grid (status/renewal/cancel/**manual override**); renewal-prompt & early-bird messaging config.
- **B5 Access control & logging:** admin-only management middleware (ACL); renewal + admin-config logging.
- **B6 Landing revamp + FAQs** (savings section deferred).
- **B7 QA / demo / staging / prod deploy** + renewal-scheduler validation + test-data cleanup.

---

## 5. New DB tables
- **2A ✅:** `ahy_caliber_nation_activity_log`.
- **2B:** `ahy_caliber_nation_renewal_log`; early-access flag storage.
- **Not built:** savings ledger (deferred with pricing).

## 6. Explicitly EXCLUDED (later phase)
Member/seller **pricing** (dynamic + seller + priority + stacking/conflict + apply in cart/checkout + PLP/PDP/search display + member-pricing emails), **savings** (per-order + cumulative + header + "average savings" + savings section), **Klevu** price templates, **Day-31 discounted renewal pricing**, seller **import** adjustments, **affiliate tracking**.

## 7. Open BUSINESS decisions (for the pricing phase)
- **Same-cart pricing:** do members get member prices on items bought in the **same order** as the membership, or only on **subsequent** orders? *(Default today: subsequent orders — member group applies after activation.)*
- **Day-31 renewal incentive** discount amount/threshold.
- **Renewal retry policy (2B):** attempts / days before `renewal_pending` → `expired`.
