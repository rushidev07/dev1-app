# Caliber Nation — Phase 3 Plan (Member Pricing & Savings Engine)

> **Status:** **P0 decisions LOCKED (2026-07-13) — ready to build.** Phases 1, 2A, 2B are complete & verified live (membership spine, order-based onboarding, renewal/win-back/admin/logging). Phase 3 builds the **member pricing & savings engine** — what a member actually *pays* and what they *saved*. This is the core value proposition and unblocks ~15 of the remaining master-list items.
>
> **Scope:** additive member-pricing engine (membership + seller + category + product), admin-managed seller participation, global discount cap, apply pricing in cart/checkout totals, savings calculation + lifetime cumulative tracking, storefront display (PDP / listing / header / account / emails), seller import, discount APIs.
>
> **Deferred to Phase 4:** **Klevu / search-results pricing display** (explicitly out of Phase 3), early-access flags + early-bird timing/messaging + badges (ex-B3), landing revamp + FAQs (ex-B6), cross-touchpoint messaging config, affiliate-tracking compatibility, final QA/deploy hardening (ex-B7).
>
> **BUILD STATUS (2026-07-13):** ✅ **P0** decisions · ✅ **P1** data model + config · ✅ **P2** resolver engine (verified: worked example + 4-layer stack → $60) · ✅ **P3** cart/checkout observer · ✅ **P4** savings + lifetime cumulative + discount_used log · ✅ **P5** ViewModels + account/PDP display (header block + listing render need live theme wiring) · ✅ **P6** seller-participation grid + category price-rule grid + **product discount on the product edit form** (product attributes; grid is category-only — no product/grid duplication) · ✅ **P7** read-only member-price API · ✅ **P8** engine verified end-to-end. **Pricing disabled by default** (admin master switch). **Remaining live-only checks:** on-site PDP/cart render, admin form save click-through, category/listing/header theme placement, product-import columns (Phase 4).

---

## 0. Requirement → Phase 3 workstream mapping
| Requirement (master list) | Workstream |
|---|---|
| Define price priority · stacking behavior · conflict resolution | **P0 (locked)** |
| Define seller opt-in model + Caliber pricing structure | **P0 (locked) → P6** |
| DB schema for incentive + savings tracking | **P1** |
| Dynamic member pricing logic · seller-specific pricing · membership product stacking | **P2 + P3** |
| Apply member pricing in cart, checkout totals | **P3** |
| Calculate + store savings per order · cumulative member savings · log discount usage | **P4** |
| Regular vs Caliber price on listing · comparison + savings on PDP · header cumulative savings · account savings · "average savings" messaging | **P5** |
| Member pricing in order + subscription emails | **P5** |
| Manage seller opt-in + Caliber pricing (admin grid) | **P6** |
| Adjust import for Caliber participation + pricing | **P6** |
| APIs for discount rules · correct data flow to frontend | **P7** |
| Member pricing in **search results / Klevu** | **Phase 4 (deferred)** |
| Demo · staging · QA · deploy | **P8** |

---

## 1. P0 · LOCKED DECISIONS (the pricing & savings model)

### 1.1 Discount model — additive / stacked
Member pricing is built from **stackable discount layers**, all additive:

| Layer | Scope | Set by | Value type |
|---|---|---|---|
| **Membership base** | all products, for any active member | Admin (global config) | fixed **or** % |
| **Seller** | all products of a participating seller | Admin (seller grid — see 1.4) | fixed **or** % |
| **Category** | products in a category | Admin — **Member Price Rules** grid (category-only) | fixed **or** % |
| **Product** | a specific product | Admin — **product edit form** ("Caliber Nation Member Pricing" section) | fixed **or** % |

**Rules of the engine:**
1. **Additive** — every matching layer applies and they sum. (Not "most-specific wins.")
2. **Percentages are computed on the ORIGINAL regular price**, never on a running/already-discounted total. Fixed amounts are absolute.
3. **Order of operations is irrelevant** — because every % is off the original and the cap is on the total, the sum is commutative. No sequencing needed.
4. **Global cap** (1.3) clamps the total member discount as the final step.

### 1.2 Worked example
Product **$100**, membership **10%**, category **$50 off**, product **20%**, global cap = **max $40 off**:
```
membership 10% of 100 = $10
category  (fixed)      = $50
product   20% of 100   = $20
raw member discount    = $80        (same in any order)
apply global cap:  min($80, $40) = $40
MEMBER PRICE = 100 − 40 = $60
```

### 1.3 Global discount cap
- **Admin-configurable**, **global** (one storewide setting).
- Semantics: a **maximum total member discount** — expressed **either as a % (max X% off) or a fixed amount (max $X off)**.
- Governs **only the member discount** — it does **not** constrain coupons/cart rules (1.5). Protects membership-pricing margin, not promotional pricing.

### 1.4 Seller participation — admin-controlled
- **Not all sellers participate.** Participation is decided by the **admin**, not self-service by sellers.
- Build an **admin grid to manage seller participation + each seller's Caliber discount** (enable/disable a seller, set their fixed/% value).
- Seller discount only applies for sellers the admin has enabled.

### 1.5 Coupons / cart price rules — stack on top
- After the member price is computed (and capped), **Magento coupons / cart price rules apply on top** of the member price.
- Technically natural: the member price becomes the item's effective base price *before* the cart-rule totals stage runs, so promos come off the discounted price automatically. (Build note: member-price adjustment must run **before** the cart-rule totals collector — see P3.)
- The global cap does **not** limit coupons; a coupon can push price below the cap (Magento owns that).

### 1.6 Savings
- **Per-line savings = regular price − final price paid.**
- **Cumulative savings = LIFETIME** (sum across all the member's orders).
- **An expired member's cumulative total does NOT reset** — it persists across lapses/renewals.
- **Cap-clip attribution (display only):** when the cap clips the raw discount, attribute the capped amount to layers in priority order membership → seller → category → product until exhausted. Cosmetic only — does not change the price paid.

### 1.7 Visibility to non-members
- **Member pricing is publicly visible** — logged-out shoppers and non-members see a **teaser** ("Members pay $X" / regular vs Caliber price).
- Implication: the *displayed* member price is the same for everyone, so full-page cache does **not** need per-customer-group variation for the teaser. Only the **price actually charged in cart/checkout** depends on active membership.

### 1.8 Tax & rounding
- Member discount applies **pre-tax** (discount the catalog price, then tax the discounted price).
- **Standard rounding.**

---

## 2. Data model additions (P1)

- **`ahy_caliber_nation_member_price_rule`** — the **category** discount layer (category-only as built):
  `entity_id`, `scope` (`category`), `target_id`, `discount_type` (`fixed|percent`), `discount_value`, `is_active`, `created_at`, `updated_at`. Indexes: `(scope, target_id)`, `is_active`.
- **Product discount layer → product attributes** (NOT this table): `caliber_member_discount_type` (percent|fixed) + `caliber_member_discount_value`, set on the **product edit form** ("Caliber Nation Member Pricing" group, added by `Setup/Patch/Data/AddMemberPricingProductAttributes`). The resolver reads the product layer from these attributes. This keeps product pricing on the product itself — no target-id lookup.
  *(Membership base discount + global cap live in admin config, not this table.)*
- **`ahy_caliber_nation_seller_participation`** *(or a flag on the rule)* — which sellers the admin has enabled: `seller_id`, `is_enabled`, plus their seller-scope rule. Backs the 1.4 admin grid; linked to the Webkul seller entity.
- **`ahy_caliber_nation_order_savings`** — savings per order line: `entity_id`, `order_id`, `order_item_id`, `customer_id`, `product_id`, `seller_id` (nullable), `regular_price`, `member_price`, `paid_price`, `savings`, `applied_rules` (JSON breakdown for attribution), `created_at`. Indexes: `customer_id`, `order_id`.
- **Cumulative lifetime savings** — denormalized column on `ahy_caliber_nation_membership` (`lifetime_savings`, incremented on order completion, **never reset**). Reconcilable against SUM(`order_savings`).
- **Discount-usage logging** — reuse `ahy_caliber_nation_activity_log` with a new `discount_used` action (satisfies the master-list discount-usage logging item).
- **Admin config additions** (`caliber_nation` section, new `pricing` group): membership base discount `type`+`value`; global cap `type`+`value`.
- **Reuse:** `ahy_caliber_nation_membership`, member customer group (Phase 1), `catalog_product_entity`, `catalog_category_entity`, Webkul seller tables.

---

## 3. Workstreams

### P1 · Pricing/savings data model
Create the tables/attributes + admin config (§2); repositories + Api interfaces following house `Api/` + `di.xml` preference pattern.

### P2 · Member price resolution engine
- A single `Service/Pricing/MemberPriceResolver` — given (product, seller, customer) returns effective member price + per-layer breakdown, implementing the **additive stack** (1.1), **% on original** (1.1.2), and **global cap** (1.3). One source of truth reused by display, cart, and savings.
- Collect matching layers: membership base (config) + seller rule (if seller enabled) + category rule(s) (grid) + product discount (product attributes); sum; clamp to cap.
- **Multiple category matches:** sum all matching category rules (additive is consistent) — no precedence needed.

### P3 · Apply pricing in cart & checkout
- Apply the resolved member price to the quote item so it flows into **cart + checkout totals**; extend the existing `sales_quote_collect_totals_before` pattern (already used by win-back).
- **Ordering:** member-price adjustment must run **before** the cart price-rule/discount collector so coupons stack on top (1.5).
- Correct with mixed carts (membership product keeps its own price), pre-tax discounting (1.8), and Webkul multi-seller orders.

### P4 · Savings calculation & storage
- On order placement/completion: per line compute `regular − paid`, write `order_savings` (with `applied_rules` breakdown), increment `membership.lifetime_savings` (never reset).
- Write a `discount_used` activity-log entry per discounted order.

### P5 · Storefront display (search/Klevu EXCLUDED — Phase 4)
- **PDP:** regular vs Caliber price + savings/comparison messaging.
- **Listing / category:** regular vs Caliber price — **shown to everyone** (teaser, 1.7).
- **Header:** cumulative **lifetime** savings for active members.
- **My Account:** savings block (extend `MembershipOverview`).
- **Emails:** member pricing + savings in order + subscription emails (extend `welcome`/`renewal`).
- **"Average savings" / comparison messaging** where required.

### P6 · Seller participation, admin & import
- **Admin grid** to manage seller participation + each seller's Caliber discount (1.4) — ACL under the existing `Ahy_CaliberNation::membership` resource (or a new pricing resource).
- Admin management of **category** rules via the grid; **product** discount set directly on the product edit form (product attributes — one place per layer, no duplication).
- **Adjust product/seller import** to accept Caliber participation + pricing columns.

### P7 · APIs & data flow
- Read-only, auth-scoped API exposing effective member price / discount rules for the frontend (and future headless/Klevu surface). **No payment-bypass surface** (the earlier REST signup endpoint was removed for that reason).

### P8 · QA & deploy
- Matrix: member vs non-member; participating vs non-participating seller; multiple stacked layers; cap clipping; coupon-on-top; mixed cart; pre-tax correctness; savings accuracy + lifetime persistence across expiry; teaser display + cache correctness. Staging → deploy.

---

## 4. Build sequence & status
1. ✅ **P0** — decisions locked (this section)
2. ⏳ **P1** — pricing/savings data model + admin config
3. ⏳ **P2** — member price resolution engine (additive + cap)
4. ⏳ **P3** — apply in cart/checkout (before cart-rule collector)
5. ⏳ **P4** — savings calc + lifetime cumulative + discount logging
6. ⏳ **P5** — storefront display (PDP/listing/header/account/emails; **no search/Klevu**)
7. ⏳ **P6** — seller participation admin grid + category/product rules + import
8. ⏳ **P7** — discount APIs / data flow
9. ⏳ **P8** — QA + deploy

---

## 5. Acceptance criteria
- **P2:** for any (product, seller, customer) the resolver returns a deterministic member price = original − min(Σ layers, cap), with a per-layer breakdown; % always off original.
- **P3:** active member sees member pricing in cart **and** checkout totals; non-member pays regular; coupons stack on top of the member price; mixed carts + pre-tax correct.
- **P4:** each member order writes accurate per-line savings; `lifetime_savings` increments and **survives expiry**; a `discount_used` activity row exists per discounted order.
- **P5:** PDP/listing show regular vs Caliber price to **all** shoppers; header shows lifetime savings for active members; emails show member pricing. (Search/Klevu explicitly out.)
- **P6:** admin can enable/disable a seller and set their discount via the grid; category rules manageable via the grid; product discount set on the product edit form; import accepts Caliber columns.
- **P7:** frontend obtains correct effective pricing via API; no payment-bypass surface.

---

## 6. Dependencies & risks
- **Cache / teaser (1.7):** the displayed member price is uniform for all shoppers, so FPC needs no per-group variation for display — but the **charged** price must still resolve per membership status in cart. Keep the display value and the charged value from diverging.
- **Cart-rule ordering (P3):** member price must apply before the cart-rule collector or coupons won't stack correctly (1.5).
- **Webkul multi-seller:** seller-scope pricing + multi-seller order splitting interacts with our totals adjustment — verify against existing Webkul plugins in the module.
- **Cap-clip attribution (1.6):** capped-savings breakdown is display-only; keep it out of the price path.
- **Import (P6):** Caliber columns must validate seller participation + rule types on import without corrupting existing catalog import behavior.
