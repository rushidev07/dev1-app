# Caliber Nation — Phase 2A Test Cases (Account-First)

Scope: **account-first onboarding, order-based purchase, activation, member group, entry-point CTAs, cancellation, no-tax, no-refund, card-only payment, activity logging.**
Model: `/caliber-nation` collects **account details only**; payment happens at **standard checkout**. (Renewal, win-back, early access, admin grid = Phase 2B. Member pricing / savings / affiliate = excluded.)

Environment: dev1 / staging. Record notes in the Result column.

---

## 0. Setup / preconditions

| # | Step | Expected |
|---|------|----------|
| 0.1 | `bin/magento setup:upgrade` → `bin/magento indexer:reindex cataloginventory_stock catalog_product_price` → `cache:flush` | No errors. **Reindex is required** so the membership product is salable — otherwise "add to cart" fails with *"Product that you are trying to add is not available."* (The `CreateMembershipProduct` patch now also reindexes the product row automatically.) |
| 0.2 | Catalog → Products, SKU `caliber-nation-annual` | Virtual, **Tax Class = None**, price set, **Not Visible Individually**, `Is Caliber Nation Membership = Yes` |
| 0.3 | Customers → Customer Groups | **"Caliber Nation Member"** exists |
| 0.4 | Tables exist | `ahy_caliber_nation_membership`, `ahy_caliber_nation_activity_log` |
| 0.5 | The Authorize.Net **TEMP decline hack** in `Ahy/Authorizenet/.../PlaceOrderService.php` is commented out | Confirmed (done) — real gateway responses used |

---

## A. Landing page — account-first signup (`/caliber-nation`)

| # | Title | Preconditions | Steps | Expected | Result |
|---|-------|---------------|-------|----------|--------|
| A1 | New guest — happy path | Incognito, not logged in, unused email | Open `/caliber-nation` → account form (no card fields): name, email, password **+ billing address (street, city, state, ZIP, phone)** → **"Create Account & Continue"** | Account created; **default billing address saved**; **auto-logged-in**; membership added to cart; **redirected to checkout**. |
| A1b | Pay at checkout | Continue from A1 | On checkout, only **credit-card / vault** methods shown; pay | Order placed; **$0 tax** on membership line; membership `active`; customer in **member group**; vault token stored; **welcome email**; activity log `created`. |
| A2 | Logged-in non-member | Logged in, not a member | Open `/caliber-nation` → form prefilled, **no password fields** → **"Join & Continue to Checkout"** | Membership added to cart → checkout → pay → active. No duplicate account. |
| A3 | Existing email (guest) | Email already has an account, not logged in | Enter that email + details → Continue | Blocked: **"An account with this email already exists. Please log in to continue."** + **login link**. No duplicate account. |
| A4 | Already an active member | Logged-in active member | Open `/caliber-nation` | **"You're Already a Member!"** card + "View My Membership"; no form. |
| A5 | Weak password / mismatch | New guest | Password below policy, or Confirm ≠ Password | Inline error; cannot proceed; no account created. |
| A6 | Membership already in cart | Repeat Continue / re-open | Trigger add twice | Cart contains the membership **once** (deduped). |

---

## B. Entry-point CTAs + in-cart purchase

| # | Title | Preconditions | Steps | Expected | Result |
|---|-------|---------------|-------|----------|--------|
| B1 | Cart CTA — non-member | Guest or logged-in non-member; any item in cart | Open the cart page | **"Members save more… Join & Save"** banner shown; button links to `/caliber-nation`. |
| B2 | Cart CTA — hidden for member | Logged-in active member | Open the cart page | Banner **not** shown. |
| B3 | Registration CTA | Not logged in | Open the Create Account (register) page | "Join & Save" banner shown, links to `/caliber-nation`. |
| B4 | Buy membership with a physical product | Logged-in non-member | Add membership (via landing) + a physical product → checkout → pay | **Single order** with both; membership activates; physical item unaffected. |
| B5 | No tax on membership | Membership in cart | View cart / checkout totals | Membership line shows **$0 tax**. |
| B6 | Card-only restriction | Membership in cart | Checkout payment step | Only credit-card / vault methods shown; others hidden. Remove membership → all methods return. |
| B7 | Guest safety net | Membership somehow checked out as guest (if allowed) | Complete guest checkout | Account auto-created for the email + membership linked (set-password email sent). *(Primary flow forces account-first, so this is a fallback.)* |

---

## C. My Account — overview & management (`/caliber-nation/account`)

| # | Title | Steps | Expected | Result |
|---|-------|-------|----------|--------|
| C1 | Guest redirected | Visit while logged out | Redirected to login |
| C2 | Overview | Logged-in active member opens page | Status **Active**, renewal date, masked payment method, auto-renew status, tier Annual |
| C3 | Auto-renew toggle | Toggle off then on | Persists; success msg; `auto_renew` updates; activity log `auto_renew_toggle` |
| C4 | Cancel | Cancel → confirm | Status **cancelled**, auto-renew off, **group reverted** to General, activity log `cancelled` |
| C5 | Rejoin | Cancelled member rejoins | Back to `active`; group re-assigned |

---

## D. No-refund guard

| # | Title | Steps | Expected | Result |
|---|-------|-------|----------|--------|
| D1 | Credit memo blocked | Admin → invoiced membership order → Credit Memo → Refund | Blocked: **"Caliber Nation membership purchases are non-refundable."** |

---

## E. Data / audit

| # | Title | Expected | Result |
|---|-------|----------|--------|
| E1 | Membership row | `status=active`, tier=annual, start_date, renewal_date = now + configured months, auto_renew=1, payment_token_id set |
| E2 | One row per customer | Repeated purchase/rejoin → still exactly one row (unique customer_id) |
| E3 | Activity log | Rows `created`, `cancelled`, `auto_renew_toggle` with correct customer_id (+ order_id on create) |
| E4 | Trial honored | Enable bonus month(s) → renewal_date = now + (12 + bonus) months |

---

## F. Routing (verify — was unconfirmed)

| # | Title | Steps | Expected | Result |
|---|-------|-------|----------|--------|
| F1 | Landing resolves | Visit `/caliber-nation` (logged out) | The **CMS marketing page + signup form** renders (HTTP 200), NOT a 404 |
| F2 | Account route | Visit `/caliber-nation/account` | Membership account page (or login redirect if guest) |
| F3 | If F1 404s | — | The module `frontName` collides with the CMS page identifier → rename `frontName` (route id unchanged) so the CMS page keeps `/caliber-nation`. |

---

## Status: end-to-end VERIFIED ✅ (live on dev1)
A new guest completed: account → billing address → cart → card-only checkout → **completed order** ($99.99, $0 tax) → **active member (group 6)**, activity logged. Account dashboard renders cleanly.

## Compatibility fixes applied during testing (context for testers)
- **Reindex required** after `setup:upgrade` (step 0.1) — membership product must be in the stock + price index or add-to-cart fails / totals show $0. The creation patch now reindexes the product row automatically.
- **Free-gift promo (cart rule #185)** excluded the membership SKU — otherwise a physical "Free Everest Decal" was added, breaking the virtual cart.
- **Hyva virtual-cart checkout** crash fixed via `Plugin/HyvaCheckoutVirtualBillingFix` (addressless customers).
- **Webkul MpMultiShipping** crash on virtual orders fixed via `Plugin/WebkulVirtualOrderShippingFix`.
- **Billing address collected at signup** — the custom Ahy checkout doesn't collect billing for virtual carts, so it's captured on the signup form and saved as default billing.
- **Legacy `Ahy_Caliber` Yotpo block removed** from the My Account dashboard (it keyed off group 6 and errored). Legacy module untouched.

## Known items / notes
- Landing page **no longer collects payment** — payment is at standard checkout. `MembershipOrderService` and the old inline card form are removed.
- **Auto-login after signup** is enabled — a new guest is logged in before being sent to checkout.
- **⚠️ Vault token not linked (2B):** `ahy_caliber_nation_membership.payment_token_id` is empty — Authorize.Net vaults the card *after* our activation observer runs. The token exists but isn't linked; **auto-renewal (2B) needs this** (first 2B task).
- **⚠️ Charge-before-validate:** `Ahy_Authorizenet` charges before validating the order — failed attempts can still charge the sandbox card. Void stray sandbox charges during testing.
- **Checkout CTA** (signup during checkout) is not yet placed — Hyva checkout SPA needs specific integration; the cart CTA covers pre-checkout.
- Member-only feature gating in 2A = login-required account pages + service-layer membership checks; admin-only ACL + broader middleware come in 2B.
