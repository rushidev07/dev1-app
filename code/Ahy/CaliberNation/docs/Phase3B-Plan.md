# Caliber Nation — Phase 3B (Member Pricing Refinements & Admin UX)

> **Status:** Refinements made during Phase 3 testing. Phase 3 delivered the pricing/savings engine (see **docs/Phase3-Plan.md**); 3B is the round of UX fixes and corrections found while exercising it live. All items below are **built + verified** unless noted.
>
> **Theme:** make the admin config effortless (no raw IDs), place the storefront teaser correctly, and keep cart prices fresh. No change to the locked pricing model (P0 decisions stand).

---

## 3B.1 · Product discount moved to the product edit form
**Why:** the Member Price Rules grid required the admin to know/enter a product's numeric target-id — clunky.
**Change:** product-level member discount is now set **on the product itself**, in a "Caliber Nation Member Pricing" section, via two product attributes:
- `caliber_member_discount_type` (None | Percentage | Fixed)
- `caliber_member_discount_value` (amount)

The resolver reads the **product layer** from these attributes; the **Member Price Rules grid is category-only** (Scope dropdown offers Category only). One place per layer — no duplication / double-count.
**Files:** `Model/Product/Attribute/Source/MemberDiscountType.php`, `Setup/Patch/Data/AddMemberPricingProductAttributes.php`, `Model/Service/Pricing/MemberPriceResolver.php` (`extractProductDiscount`, new `$productDiscount` arg to `resolve()`), `Model/Config/Source/RuleScope.php` (category-only).
**Verified:** Delta-8 product attr (20%) resolved through `resolveForProduct` (member $10 with base+cap). Grid no longer offers Product scope; stale product-scope rows removed.

## 3B.2 · Searchable **seller** picker (admin)
**Why:** Seller Participation required typing a raw Webkul seller id (1211 rows).
**Change:** the "Seller" field is a **searchable ui-select dropdown** (`Magento_Ui/js/form/element/ui-select`, `filterOptions: true`) listing sellers as `Shop title / email (#id)` — 621 unique, sorted.
**Files:** `Model/Seller/Options.php` (option source), `view/adminhtml/ui_component/ahy_caliber_nation_seller_participation_form.xml` (field via verbose config-array), `Controller/Adminhtml/SellerPricing/Save.php` (normalise ui-select array → int).
**Verified:** 621 options; seller 17102 resolvable; save normalises the value.

## 3B.3 · Searchable **category** picker (admin)
**Why:** same raw-id problem on the Member Price Rules form.
**Change:** the "Category" field is a **searchable ui-select dropdown** listing categories with **breadcrumb path** labels (`Parent > Child (#id)`) — 2159 categories, disambiguating repeated names.
**Files:** `Model/Category/Options.php` (option source, path labels), `view/adminhtml/ui_component/ahy_caliber_nation_member_price_rule_form.xml` (field via verbose config-array), `Controller/Adminhtml/PriceRule/Save.php` (normalise ui-select array → int).
**Verified:** 2159 options; category 3658 → "Local Laws Category > Delta-8 (#3658)"; save normalises the value.

> **Admin ui-select recipe (for future forms):** this Magento version's form XSD rejects `<filterOptions>` and `<component>` inside `<settings>`/`<formElements>`. Define the searchable field entirely in the verbose `<argument name="data"><item name="config">` array (component = `Magento_Ui/js/form/element/ui-select`, `elementTmpl = ui/grid/filters/elements/ui-select`, `filterOptions = true`, `multiple = false`) with the options source as `<item name="options" xsi:type="object">…</item>`. The controller must accept an array value and reduce to a scalar.

## 3B.4 · Admin CRUD forms — spinner fix
**Why:** the Seller/Rule forms hung on an infinite spinner.
**Change:** admin **form** data-source must declare its provider via a `js_config/component` **item** (not a `component=` attribute — that's listing syntax), and the form root needs `<item name="template">templates/form/collapsible</item>`. Fixed on both forms.
**Files:** both `*_form.xml`.
**Verified:** forms render fields and buttons.

## 3B.5 · PDP teaser placement
**Why:** the regular-vs-member teaser rendered at the page bottom instead of under the price.
**Change:** in this Hyva theme the price is rendered by name (`getChildHtml("product.info.price")`) inside `product.info`, not as a positioned child of the container. The teaser block is now declared as a child of **`product.info`** and output by a single `getChildHtml("caliber_nation.member_price")` call placed right after the price in the theme template.
**Files:** `view/frontend/layout/catalog_product_view.xml` (block under `product.info`), `app/design/frontend/Ahy/Everest2/Magento_Catalog/templates/product/view/product-info.phtml` (one `getChildHtml` line — the only out-of-module change).
**Verified:** teaser shows directly under the price ("You pay $X / Save $Y").

## 3B.6 · Cart price staleness fix
**Why:** an item kept the `custom_price` persisted when it was added, so a changed applicable price didn't show (e.g. a shopper who **becomes a member** while an item sits in the cart, or an admin rule change).
**Change:** on cart view, force the quote to **recollect totals** so `ApplyMemberPrice` re-applies the current member price, then re-save so the mini-cart reflects it.
**Files:** `Observer/RecollectCartTotals.php`, `etc/frontend/events.xml` (`controller_action_predispatch_checkout_cart_index`).
**Verified:** resolver returns fresh price; cart recollects on view. *(Confirm on-site that the stale $12 line updates to the current price.)*

## 3B.7 · Guard — active members can't re-add the membership
**Why:** an active member should not be able to buy the membership again (renewals are automatic).
**Change:** an observer on `checkout_cart_product_add_before` blocks adding the membership SKU when the customer `isActiveMember()`. Expired/cancelled/non-members are **not** blocked (win-back re-buy still works). The landing page already shows "You're already a member"; this is the deeper backstop. The signup Magewire now surfaces the guard's `LocalizedException` message.
**Files:** `Observer/PreventDuplicateMembership.php`, `etc/frontend/events.xml`, `Magewire/MembershipSignup.php` (catch `LocalizedException` → show its message).
**Verified:** DI + landing-page member state; guard fires on the add path.

## 3B.8 · Header cumulative savings (customer-data section)
**Why:** show active members their lifetime savings in the header ("You've saved $X") — reinforcement.
**Change:** the header is full-page-cached, so the per-customer figure is served via a **customer-data (JS) section**, never baked into cached HTML.
- `CustomerData/MemberSavings.php` (`SectionSourceInterface`) → `{ is_active_member, lifetime_savings, formatted }`.
- `etc/di.xml` registers it as the `member-savings` section; `etc/frontend/sections.xml` refreshes it on checkout success + login.
- Header snippet (Alpine) next to the account menu reads the section via `@private-content-loaded` and shows "You've saved $X" **only for active members with `lifetime_savings > 0`** (hidden otherwise). Cache-safe.
**Files:** `CustomerData/MemberSavings.php`, `etc/di.xml`, `etc/frontend/sections.xml`, `Magento_Theme/templates/html/header.phtml` (desktop; mobile can be added later).
**Verified:** section source returns correct data per customer; badge appears after the section refreshes (login). Uses the existing `HeaderSavings` ViewModel's logic but via a cache-safe section (the ViewModel itself is now unused for the header).

## 3B.9 · Listing/category teaser — NOT via native template (Klevu)
**Finding:** category & search listings are rendered by **Klevu** (client-side JS templates, e.g. `Ahy/ThemeCustomization/.../klevu/klevu_landing_template_product_block.phtml`), not Magento's native `list/item.phtml`. A native PHP teaser therefore never renders on those pages.
**Change:** the native `item.phtml` teaser (and the resolver `extraCategoryIds` / ViewModel current-category additions that supported it) were **removed** — they were inert on the Klevu-powered PLP. `resolveForProduct()` and `MemberProductPrice` are back to their PDP-only form.
**Deferred to Phase 4:** showing member pricing on Klevu listing/search cards = the Klevu dynamic-pricing integration (Phase 3 Q7 — explicitly out of scope). PDP + account + header displays remain done.

---

## Where each discount layer is configured (after 3B)
| Layer | Admin location |
|---|---|
| Membership base | Stores → Config → Caliber Nation → **Member Pricing** |
| Seller | Caliber Nation → **Seller Participation** (searchable seller picker) |
| Category | Caliber Nation → **Member Price Rules** (searchable category picker) |
| Product | **Product edit form** → "Caliber Nation Member Pricing" |
| Global cap | Member Pricing config |

## Still pending (carried from Phase 3)
- **TC-SAV** — savings on a real order (order_savings + lifetime_savings + discount_used) — not yet run live. (Makes the header savings figure real; a test value was used to verify the badge.)
- Live confirmation of: checkout totals (TC-CART-2), coupon stacking (TC-CART-4), cart-recollect on-site.
- **Header savings** — built (3B.8); confirm live badge after login. Mobile-header version not yet added.
- **Listing/search member pricing** — deferred to **Phase 4** (Klevu integration, 3B.9). Native PDP + account + header done.
- Member pricing in **emails**; **product/seller import** columns (Phase 4).
- Optional: grids still show numeric seller_id/target_id columns (could show names).
