# Configurable Products — Member Pricing Plan

Status: **planned, not implemented**
Scope: make Caliber Nation member pricing work for configurable products on the PLP,
the PLP options drawer, and in the cart.

---

## 1. Why it doesn't work today

`MemberPriceResolver::resolveForProduct()` reads the price off the product:

```php
// MemberPriceResolver.php:41
$regular = $regularPrice ?? (float) $product->getPrice();
```

and then bails before evaluating any discount layer:

```php
// MemberPriceResolver.php:80
if (!$this->config->isPricingEnabled() || $regularPrice <= 0) {
    return new PriceResult($regularPrice, $regularPrice, 0.0, 0.0, false, []);
}
```

A configurable product's `price` attribute is **0** — Magento stores no price on the
parent; the displayed "From $39.99" is computed at render time from the children. So
`$regularPrice = 0`, the guard fires, and every caller is told "no discount".

Measured impact: **6,851 of 7,083 configurable products (96.7%)** have `price` 0 or NULL.
The feature is silently invisible on all of them. Nothing errors and nothing logs — the
API returns a valid "no discount" response, which is why it reads as a config problem.

Verified against the live products on the Shooting Sports PLP:

| Product | Type | `getPrice()` | Resolver |
|---|---|---|---|
| 272555 — *[BYU] Cooler Toppers* (the PLP card) | configurable | **0** | `hasDiscount: no` |
| 272553 — its 30 Quart child | simple | 39.99 | 39.99 → **34.99** |
| 272557 — *Caddis Sports Fins* | simple | 39.99 | 39.99 → **34.99** |

The seller gate already works correctly — `SellerResolver` maps all of these to seller
16659.

---

## 2. Locked decisions

**A. "From" semantics when only some variants are discounted**

| Case | Card copy | Figure used |
|---|---|---|
| All variants discounted | "Members pay from **$X**" | lowest member price |
| Some variants discounted | "Members pay from **$X** on select options" | lowest member price **among discounted variants only** |
| No variants discounted | *(no badge)* | — |

The qualifier matters: if the cheapest variant is *not* discounted, the lowest member
price across all variants is the non-member price, and an unqualified "from" would
misrepresent it.

**B. No parent → child inheritance**

Product-level member discounts apply **only** to the product they are set on. A discount
set on a configurable parent does **not** cascade to its variants. Per-variant control is
the only mechanism for configurables.

Consequence to mitigate: setting the fields on a configurable parent becomes a silent
no-op. See workstream 5.

---

## 3. Core principle

**Resolve per child variant, never on the parent.** The parent carries no price; the
children do. Everything the resolver needs is available per child:

| Input | Source for a child variant | Verified |
|---|---|---|
| Regular price | child's own `price` / `catalog_product_index_price` | ✅ 39.99 / 49.99 / 89.99 |
| Seller (participation gate) | `marketplace_product` — children are mapped | ✅ all → 16659 |
| Product-level discount | child's own `caliber_member_discount_*` | ✅ attrs present in all 34 attribute sets |
| Categories | `union(parent categories, child categories)` | ⚠️ see below |

**Category union is required.** Children sit in 1 category, the parent in 4. Resolving
purely per child would silently drop category-level discounts attached to the parent's
categories.

`resolveForProduct(ProductInterface $product, ?float $regularPrice = null)` already
accepts an explicit price — the parameter exists and is currently unused by all three
callers. Most of this work is about *what price gets passed*, not new architecture.

---

## 4. Workstreams

### WS1 — Per-variant resolution in the pricing core  ·  size M

New aggregator (e.g. `Model/Service/Pricing/ConfigurableMemberPrice`) that, given a
configurable product:

1. loads child IDs from `catalog_product_super_link` (one query, batched across all
   products on the page);
2. loads child prices from `catalog_product_index_price` rather than full product models;
3. calls the existing resolver per child, with the child's own product-level discount and
   `union(parent, child)` categories;
4. returns a per-variant map plus a derived `from` entry and the `allDiscounted` flag.

No change to how simple products resolve.

### WS2 — Cart correctness  ·  size S  ·  **must ship with WS1**

`Observer/ApplyMemberPrice.php` skips child rows (`if ($item->getParentItemId()) continue;`)
and resolves the parent — price 0 — so configurables get no member price at checkout.

Fix: for a configurable parent item, resolve rules against the selected child
(`$item->getOptionByCode('simple_product')`) and pass `$item->getPrice()` as the explicit
regular price.

Ship together with WS1. Display-only would have the PLP promise a discount the cart does
not honour — worse than showing nothing.

*(Static read of the code; confirm with a real member cart before/while fixing.)*

### WS3 — PLP card display  ·  size S

`Controller/Pricing/MemberPrice.php` returns the extended payload (below). The existing
Klevu overlay JS
(`Everest2/Klevu_Categorynavigation/templates/html/head/js_additional.phtml`) already
scans `[id^="kuAddToCartBtn-"]`, batches the fetch, and injects a badge — it only needs to
read the `from` entry and apply the decision-A copy.

### WS4 — Options drawer  ·  size M

The drawer is `ThemeCustomization/.../klevu/landing_page_product_addToCart.phtml` →
`ahy_themecustomization/index/GetProductSwatchesDetails`, which returns
`product_price_html` + `product_price_script` and renders into `#product-price-ahy`.

1. Add a `caliber_prices` key (the same variants map) to that controller's JSON.
2. Render a member-price line beneath `#product-price-ahy`.
3. Update it wherever the existing price script reacts to swatch selection, keyed by the
   selected child ID.

Because the map is keyed by child ID, a variant with no discount simply hides the line —
exactly the behaviour decision A requires for the partial case.

### WS5 — Admin guardrail  ·  size S

Decision B makes parent-level fields a silent no-op on configurables. Add a product-form
modifier (same pattern as the existing `MemberDiscountDecimal` modifier) that, for
configurable products, disables the three fields and shows:

> Set member discounts on each variant. A discount set here does not apply to variants.

Prevents a misconfiguration that otherwise produces no feedback at all.

---

## 5. Data contract

Simple products keep their current shape, so existing behaviour is untouched:

```json
{
  "pricing_enabled": true,
  "is_member": false,
  "prices": {
    "272557": { "hasDiscount": true, "memberPrice": 34.99, "regularPrice": 39.99, "…": "…" },

    "272555": {
      "type": "configurable",
      "hasDiscount": true,
      "allDiscounted": false,
      "from": { "memberPrice": 44.99, "regularPrice": 49.99, "…": "…" },
      "variants": {
        "272553": { "hasDiscount": false },
        "272551": { "hasDiscount": true, "memberPrice": 44.99, "regularPrice": 49.99 },
        "272549": { "hasDiscount": true, "memberPrice": 84.99, "regularPrice": 89.99 }
      }
    }
  }
}
```

---

## 6. How the three discount scenarios resolve

| Scenario | Behaviour |
|---|---|
| Seller-wide discount only | All children share the seller → all variants discounted → unqualified "from" |
| No seller-wide, product-level only | Participation gate must still pass (seller enabled, blanket type None/0), then each child's own attributes decide |
| Discount on 2 of 3 variants | Partial → "on select options" copy; drawer shows the member line only on the two discounted variants |
| Discount set on the parent only | **No effect** (decision B) → WS5 warns the admin |

---

## 7. Edge cases

- **Cap interaction** — the global cap clamps per variant, so a percent discount can be
  capped on an expensive variant but not a cheap one. `from` must be computed *after*
  capping.
- **Out-of-stock variants** — exclude from the `from` calculation; otherwise the card
  advertises a price nobody can buy.
- **Disabled variants** — same, exclude.
- **Single-variant configurables** — `allDiscounted` is trivially true; suppress the
  qualifier.
- **Price index staleness** — if `catalog_product_price` is invalid, child prices may be
  stale. Prefer the index for speed, fall back to the product model when a row is missing.
- **Klevu sorting is unaffected** — Klevu still serves and sorts on the base price. Sorting
  by member price is out of scope (see §9).

---

## 8. Test matrix

| # | Setup | Expect — PLP card | Expect — drawer | Expect — cart |
|---|---|---|---|---|
| 1 | Seller-wide, all variants | "from $X" | member line on every variant | discount applied |
| 2 | Seller not participating | no badge | no line | no discount |
| 3 | Seller enabled + blanket None, product discount on 1 of 3 children | "from $X on select options" | line on that child only | discount only for that child |
| 4 | Product discount on 2 of 3 | "from $X on select options" | line on those 2 | per selection |
| 5 | Discount only on the *most expensive* variant | "from $X on select options", X = that variant's member price | line on it only | applied |
| 6 | Discount on parent only | no badge | no line | no discount |
| 7 | Cheapest variant out of stock | `from` ignores it | n/a | n/a |
| 8 | Cap clips an expensive variant | capped member price used | capped | capped |
| 9 | Guest vs member | guest: "Members pay…" teaser · member: price replaced | same | member only |
| 10 | Simple products (regression) | unchanged | n/a | unchanged |

---

## 9. Explicitly out of scope

- **Indexed member price + Klevu sync.** Would enable sort/filter by member price and
  member prices in search results and recommendations. Confirmed impossible today —
  `catalog_product_index_price` group 6 rows are identical to group 0. Large build:
  indexer, invalidation on every rule/seller/config change, Klevu sync, and
  `klevu_search/price_per_customer_group` enabled. Cron is currently not running.
- **Full PDP page** for configurables — same root cause, shares the WS1 variant map.
  Should follow immediately after, but is not part of this plan.
- **Bundle and grouped products** — same root cause (no own price). None in the catalog
  today; WS1's aggregator should be written so they can be added.

---

## 10. Sequence

1. **WS1 + WS2 together** — correctness. Nothing user-visible without them.
2. **WS3** — the PLP card, the surface being tested.
3. **WS4** — the drawer.
4. **WS5** — admin guardrail (can land any time; cheap).

---

## 11. Risks

| Risk | Mitigation |
|---|---|
| Per-variant resolution multiplies work (~20 cards × 3–10 variants ≈ 200 resolutions/page) | Batch child IDs in one `catalog_product_super_link` query; take prices from the price index, not product models; keep the existing per-request cache |
| Regression on simple products | Simple path unchanged; test 10 in the matrix guards it |
| Category union changes existing simple-product results | Union applies only to the configurable child path |
| Cart fix touches order totals | Verify against a real member cart plus `SavingsRecorder` output before shipping |
