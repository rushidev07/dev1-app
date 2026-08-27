# Cart Price Rule Changes (Caliber Nation)

> **Why this doc exists:** Some Caliber Nation behavior depends on **Magento Cart Price
> Rules**, which are **admin/database configuration — NOT module code**. They do **not**
> live in `app/code/Ahy/CaliberNation`, do **not** appear in a git diff, and are **not**
> re-created by `setup:upgrade`. If the database is reset/reimported, or the rule is
> re-saved in admin without these settings, the behavior regresses. This file is the
> source of truth for re-applying them on any environment (staging, production, a fresh
> local DB).

---

## Rule #185 — "Buy 1 product, get 1 Free Everest Decal" (Amasty Free Gift)

### What it does
A cart price rule that **auto-adds a free gift** — the `FREE Everest Decal` product
('Mystery' Everest Decal, `$0.00`) — to the cart. Type: Amasty `ampromo_cart`
("Auto add promo items for the whole cart", Number Of Gift Items = 1).

### The requirement
The membership purchase must **not** grant a free gift. The gift should only be added
when the cart contains a **real, paid product** — i.e. a product that is **neither the
membership SKU nor the gift itself**.

### The problem that was fixed
The rule originally qualified on: *"found ≥1 item where `sku != caliber-nation-annual`"*.
The free gift (`FREE Everest Decal`) is itself a product whose SKU is not the membership
SKU — so **once added, the gift satisfied its own qualifying condition** and could never
be removed. Result: after a shopper removed the real product, the cart was left with
`membership + free gift`, and the gift stayed forever (it should have dropped off).

### The required condition (THE FIX)
Under **Conditions**, the "Found in cart" rule must exclude **both** SKUs:

```
Apply the rule only if the following conditions are met:
If ALL of these conditions are TRUE:
    If an item is FOUND in the cart with ALL of these conditions true:
        SKU  is not  caliber-nation-annual
        SKU  is not  FREE Everest Decal        ← the line that must be present
```

Both `is not` lines are required, and the inner "Found" aggregator must be **ALL**.

### Why it works after the fix
| Cart contents | A qualifying item exists? | Free gift |
|---|---|---|
| real product + membership | Yes (the real product) | Added ✅ |
| membership + gift (real product removed) | No — gift & membership both excluded | Removed ✅ |
| membership only | No | Not added ✅ |

---

## How to apply / verify in admin

**Path:** Admin → **Marketing → Cart Price Rules** → **"Buy 1 product, get 1 Free Everest Decal"**
→ expand **Conditions**.

**Direct URL (local):**
`/<admin>/sales_rule/promo_quote/edit/id/185/`

Steps:
1. Expand **Conditions**.
2. Under *"If an item is FOUND in the cart with ALL of these conditions true"*, confirm
   there are **two** lines:
   - `SKU  is not  caliber-nation-annual`
   - `SKU  is not  FREE Everest Decal`
3. If the second line is missing, click the green **(+)** under the Found condition, add
   **Product Attribute → SKU**, set operator to **is not**, value `FREE Everest Decal`.
4. **Save**.
5. Flush cache.

> ⚠️ **Every time this rule is saved from admin, both `is not` lines must remain.**
> Re-saving regenerates the condition from the form; dropping either line reintroduces
> the bug (the gift will perpetuate itself, or the membership will start granting a gift).

---

## Reference (identifiers)

| Item | Value |
|---|---|
| Cart price rule id | `185` |
| Rule name | Buy 1 product, get 1 Free Everest Decal |
| Membership SKU (excluded) | `caliber-nation-annual` |
| Free gift SKU (excluded) | `FREE Everest Decal` |
| Gift link table | `amasty_ampromo_rule` (`salesrule_id = 185`) |
| Condition storage | `salesrule.conditions_serialized` (`rule_id = 185`) |

---

## New membership SKUs (future)

If additional membership products are ever added (e.g. a monthly membership), **each new
membership SKU must also be added as a `SKU is not <sku>`** line under this rule's Found
condition — otherwise buying that membership alone would incorrectly grant a free gift.
