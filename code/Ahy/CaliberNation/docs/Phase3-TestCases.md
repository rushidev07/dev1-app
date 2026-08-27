# Caliber Nation — Phase 3 Test Cases (Member Pricing & Savings)

> Covers the member-pricing engine, cart/checkout application, savings + lifetime cumulative, admin management, storefront display, and the read-only API. Pair with **docs/Phase3-Plan.md** (P0 locked decisions + workstreams).
>
> **Environment:** local dev (localhost:8090), MAMP PHP 8.1 CLI, Docker MariaDB `dev1`. Emails stay **disabled** on local (verify via Mageplaza SMTP Email Logs). reCAPTCHA disabled on local.
>
> **Engine is OFF by default** — every test below assumes you first enable it (TC-SETUP-1) unless the case is specifically testing the disabled state.

---

## Legend
- **AM** = active member (e.g. yavemi3671@dysonc.com, status `active`)
- **NM** = non-member / logged-out visitor
- Money model recap (P0 §1): additive layers `membership base + seller + category + product`, each **% off original** or **fixed amount off**; summed; clamped by a **global cap** (max total discount, % or $); coupons stack on top; member price floored at $0.

---

## 0. Setup / prerequisites

### TC-SETUP-1 — Enable the engine
1. Admin → **Stores → Configuration → Caliber Nation → Member Pricing**.
2. Enable Member Pricing = **Yes**; Base Discount Type = **Percentage**, Value = **10**; Cap Type = **Fixed amount off**, Value = **40**. Save.
- **Expect:** config saved; a `config_change` row appears in `ahy_caliber_nation_activity_log` (B5 logging).

### TC-SETUP-2 — Seed discounts (admin)
1. **Seller** — Caliber Nation → **Seller Participation → Add Seller**: Seller ID = a real Webkul seller, Participating = Yes, Type = Percentage, Value = 5. Save.
2. **Category** — Caliber Nation → **Member Price Rules → Add Rule**: Scope = Category (only option), Target ID = a real category id, Type = Fixed amount off, Value = 50, Active = Yes. Save.
3. **Product** — Catalog → **Products → edit a product** → **"Caliber Nation Member Pricing"** section → Member Discount Type = Percentage, Member Discount Value = 20 → Save. *(Product discount is set on the product itself — NOT the rules grid.)*
- **Expect:** seller + category rows in their grids; product fields saved on the product form.

### Verification SQL helpers
```sql
-- rules
SELECT * FROM ahy_caliber_nation_member_price_rule ORDER BY entity_id DESC;
SELECT * FROM ahy_caliber_nation_seller_participation;
-- savings
SELECT * FROM ahy_caliber_nation_order_savings WHERE order_id = <id>;
SELECT status, lifetime_savings FROM ahy_caliber_nation_membership WHERE customer_id = <id>;
-- discount usage log
SELECT * FROM ahy_caliber_nation_activity_log WHERE action='discount_used' ORDER BY entity_id DESC;
```

---
![alt text](image.png)
## 1. Price resolution engine (P2)

| # | Case | Setup | Steps | Expected |
|---|---|---|---|---|
| TC-ENG-1 | Base only | base 10%, no rules match | Resolve $100 product | member $90; 1 layer |
| TC-ENG-2 | Full additive stack + cap | base 10% + seller 5% + cat $50 (grid) + product 20% (product form), cap $40 | Resolve $100 product | raw discount $85 → **capped $40** → member **$60**; capped=true; 4 layers |
| TC-ENG-3 | Additive under cap | base 10% + cat $5, cap $40 | Resolve $100 | raw $15 < cap → member **$85**; capped=false |
| TC-ENG-4 | Percent on ORIGINAL (not compounding) | base 10% + product 10% | Resolve $100 | $20 off (not $19) → member **$80** |
| TC-ENG-5 | Floor at zero | product fixed $999 off, cap disabled (0) | Resolve $100 | member **$0.00** (never negative) |
| TC-ENG-6 | Cap by percent | cap Type=%, Value=30 | Resolve $100 with raw $85 | discount capped at $30 → member **$70** |
| TC-ENG-7 | Engine disabled | Member Pricing = No | Resolve any product | member == regular; 0 layers |
| TC-ENG-8 | Non-participating seller | seller row is_enabled=No | Resolve that seller's product | seller layer NOT applied |
| TC-ENG-9 | Multiple category rules | product in 2 categories, each has a rule | Resolve | BOTH category discounts summed (additive) |

> Fast check (no UI) — bootstrap the resolver:
> `MemberPriceResolver::resolveForProduct($product)` (reads the product's own discount attribute) → inspect `regularPrice/memberPrice/rawDiscount/discount/capped/layers`.
> Low-level `resolve($regular, $productId, [$categoryIds], $sellerId, ['type'=>'percent','value'=>20])` — the 5th arg is the product-level discount (from the product attribute).

---

## 2. Cart & checkout application (P3)

| # | Case | User | Steps | Expected |
|---|---|---|---|---|
| TC-CART-1 | Member sees member price in cart | AM | Add a discounted product to cart | line unit price = member price; cart total reflects it |
| TC-CART-2 | Member sees it at checkout | AM | Proceed to checkout | checkout totals match the member price |
| TC-CART-3 | Non-member pays regular | NM / logged-in non-member | Add same product | line price = **regular** (no member discount) |
| TC-CART-4 | Coupon stacks on top | AM | Apply a valid coupon/cart rule to the member-priced item | coupon comes off the **member price** (member price − coupon) |
| TC-CART-5 | Membership product excluded | AM | Add `caliber-nation-annual` to cart | membership line keeps its own price (no member discount applied to it) |
| TC-CART-6 | Mixed cart | AM | Add a discounted product + a non-rule product | discounted line = member price; other line = regular |
| TC-CART-7 | Cap respected in cart | AM | Add product whose raw discount > cap | line price = regular − cap |
| TC-CART-8 | Expired member (win-back) unaffected on catalog | expired member | Add a catalog product | member pricing NOT applied (only active members); win-back still applies to the membership SKU |

---

## 3. Savings recording + lifetime cumulative (P4)

| # | Case | Steps | Expected |
|---|---|---|---|
| TC-SAV-1 | Savings row per line | AM places an order with a discounted product | `order_savings` has a row: `regular_price`, `member_price`, `paid_price`, `savings = (regular − paid)·qty` |
| TC-SAV-2 | Lifetime increments | Check membership after order | `lifetime_savings` increased by the order's total savings |
| TC-SAV-3 | discount_used logged | Check activity log | one `discount_used` row with `order_id` + `$` in detail |
| TC-SAV-4 | Idempotent | (re-fire order place / reload) | no duplicate `order_savings` rows for the same order |
| TC-SAV-5 | Lifetime survives expiry | Expire the member, then check | `lifetime_savings` **unchanged** (does NOT reset) |
| TC-SAV-6 | Non-member order | NM (or non-member) places order with coupon | **no** `order_savings` rows, no lifetime change |
| TC-SAV-7 | Coupon included in savings | AM order with member price + coupon | `savings = regular − final paid` (includes coupon effect); `member_price` stored separately |

---

## 4. Admin management (P6)

| # | Case | Steps | Expected |
|---|---|---|---|
| TC-ADM-1 | Seller grid loads | Caliber Nation → Seller Participation | grid renders with filters/paging + "Add Seller" |
| TC-ADM-2 | Add seller | Add Seller → fill → Save | row saved; back to grid; success message |
| TC-ADM-3 | Edit seller | Edit a row → change discount → Save | value updated |
| TC-ADM-4 | Delete seller | Delete action → confirm | row removed |
| TC-ADM-5 | Rules grid loads | Caliber Nation → Member Price Rules | grid renders + "Add Rule" |
| TC-ADM-6 | Add category rule | Add Rule → Scope=Category (only option), target/type/value → Save | row saved; resolves on matching products |
| TC-ADM-7 | Set product discount on product form | Catalog → Products → edit → "Caliber Nation Member Pricing" → set Type + Value → Save | product PDP/cart reflects the product-level discount; grid Scope dropdown offers **Category only** (no Product) |
| TC-ADM-8 | Toggle active | Set a rule Active=No | rule no longer applied by resolver |
| TC-ADM-9 | ACL enforced | Log in as an admin role WITHOUT `Ahy_CaliberNation::seller_pricing` | menu/pages blocked (Access Denied) |
| TC-ADM-10 | Config-change audit | Change a Member Pricing config value | `config_change` activity row with `admin_user_id` |

---

## 5. Storefront display (P5) — search/Klevu OUT of scope

| # | Case | User | Expected |
|---|---|---|---|
| TC-DSP-1 | PDP teaser (member) | AM | PDP shows "You pay $X" + struck regular + savings |
| TC-DSP-2 | PDP teaser (non-member) | NM | PDP shows "Members pay $X" + "Join Caliber Nation" link |
| TC-DSP-3 | No teaser when no discount | any | product with no matching layers → no teaser |
| TC-DSP-4 | Account lifetime savings | AM | My Account membership block shows real cumulative savings (not $0.00) |
| TC-DSP-5 | Header savings | AM | header shows lifetime savings *(needs theme placement — see note)* |
| TC-DSP-6 | Engine off | any | no teaser anywhere |

> **Note:** PDP + account are wired via layout/ViewModels. Category/listing cards and the header block render through Hyva theme templates and may need a small theme hook — confirm on-site.

---

## 6. Read-only API (P7)

| # | Case | Steps | Expected |
|---|---|---|---|
| TC-API-1 | Member token | `GET /V1/caliber-nation/me/member-price/:productId` with an AM customer token | returns `regular_price`, `member_price`, `discount`, `has_discount=true`, `is_member=true` |
| TC-API-2 | Non-member token | same, non-member customer token | `member_price` (teaser) returned, `is_member=false` |
| TC-API-3 | No auth | call without a customer token | 401 / access denied (route is `self`-scoped) |
| TC-API-4 | No payment surface | inspect routes | only the GET member-price route exists; no write/charge endpoint |

---

## 7. Regression (must still pass from earlier phases)

| # | Case | Expect |
|---|---|---|
| TC-REG-1 | Membership signup (2A) | account-first signup → order → activation unaffected |
| TC-REG-2 | Renewal (2B, B1) | cron/charge-now renewal still works (charge + extend + log + email, no order) |
| TC-REG-3 | Win-back (2B, B2) | Day-31 discount on the membership SKU unaffected; does not double with member pricing |
| TC-REG-4 | Admin membership grid (B4) | charge now / cancel / set status still work |
| TC-REG-5 | CC-only enforcement | membership-in-cart still restricts to card methods |

---

## 8. Teardown
1. Delete test rules + seller rows via the admin grids (or SQL).
2. Set **Member Pricing = No** (or delete `caliber_nation/pricing/*` config rows) → back to default disabled state.
3. `bin/magento cache:flush`.
4. Void any sandbox charges created by regression renewal/order tests.

---

## Pass criteria summary
Phase 3 passes when: the resolver returns the locked-model prices (TC-ENG-2 = $60 is the canonical check); active members are charged member prices in cart+checkout with coupons stacking on top; non-members pay regular but see the teaser; savings + lifetime cumulative record correctly and survive expiry; admins can manage sellers + rules via the grids; and the engine is fully inert when disabled.
