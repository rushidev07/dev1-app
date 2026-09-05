# Caliber Nation — Full Testing Combinations Matrix

Reference checklist for exhaustively testing the membership + pricing engine. Pulls
real current config from this environment (values below), and reuses the SQL
patterns already established for flipping membership state. See also:
[Cart-Price-Rule-Changes.md](Cart-Price-Rule-Changes.md) (rule 185 requirements)
and [Demo-Feature-Guide.md](Demo-Feature-Guide.md) (feature walkthroughs).

## Current environment config (baseline for the numbers used below)

| Setting | Value |
|---|---|
| Master switch (`caliber_nation/general/enabled`) | 1 |
| Pricing enabled | 1 |
| Membership price | $99.99/yr |
| Membership base discount | `fixed $0` (i.e. **no base layer configured right now** — set a value to test it) |
| Global cap | `percent 50%` |
| Win-back | enabled, 20% off, eligible after 30 days lapsed |
| Trial/bonus | disabled (1 month bonus if enabled) |
| Member group id | 6 (Caliber Nation Member) |
| Seller 17102 | participating, blanket `fixed $5` |
| Category rules | none configured |

---

## 1. Membership Lifecycle State Matrix

For each state, confirm: `isActiveMember()` result, member pricing on/off, header
savings pill, PDP teaser label, account dashboard, renewal banner/popup, customer
group, and — after this session's fixes — that the free gift and cart price stay
consistent with PDP.

| # | Status | auto_renew | renewal_date | Entitled? (`isActiveMember`) | Expected group | Notes / what to verify |
|---|---|---|---|---|---|---|
| 1 | `active` | 1 | future | **Yes** | 6 | Full member pricing; auto-renews on schedule |
| 2 | `active` | 0 | future | **Yes** | 6 | Full pricing now; **must auto-expire when renewal_date passes** (fixed this session — verify `ProcessRenewals` catches it) |
| 3 | `active` | 0 | **past** | Should already be expired by cron | 1 | Regression check for the just-fixed gap — if you find this combo persisting as `active`, the cron fix broke |
| 4 | `renewal_pending` | 1 | past | **No** | 1 | Retrying charge (attempt 1–2 of 3, or <7 days old); no benefits; UI should NOT say "EXPIRED" even though it currently does (flagged, unfixed — see §11) |
| 5 | `cancelled` | 0 | **future** | **Yes** (paid-through) | 6 | Keeps benefits until renewal_date; account UI should say cancelled but still show benefits |
| 6 | `cancelled` | 0 | **past** | Should already be expired by cron (`expireCancelledMemberships`) | 1 | If still `cancelled` past its date, that cron step regressed |
| 7 | `expired` | 0 | past | **No** | 1 | Renew/win-back prompts everywhere; no pricing |
| 8 | Never a member (no row) | — | — | **No** | 1 | Signup form shows "Create Your Account" (guest) / "Complete Your Membership" (logged-in) |

**SQL to set each state** (replace the email):
```sql
-- ACTIVE
UPDATE ahy_caliber_nation_membership SET status='active', start_date=NOW(),
  renewal_date=DATE_ADD(NOW(), INTERVAL 12 MONTH),
  auto_renew=CASE WHEN payment_token_id IS NOT NULL THEN 1 ELSE 0 END, updated_at=NOW()
 WHERE customer_id=(SELECT entity_id FROM customer_entity WHERE email='EMAIL');
UPDATE customer_entity SET group_id=6 WHERE email='EMAIL';

-- EXPIRED
UPDATE ahy_caliber_nation_membership SET status='expired',
  renewal_date=DATE_SUB(NOW(), INTERVAL 7 DAY), auto_renew=0, updated_at=NOW()
 WHERE customer_id=(SELECT entity_id FROM customer_entity WHERE email='EMAIL');
UPDATE customer_entity SET group_id=1 WHERE email='EMAIL';

-- CANCELLED, still paid-through (row #5)
UPDATE ahy_caliber_nation_membership SET status='cancelled', auto_renew=0, updated_at=NOW()
 WHERE customer_id=(SELECT entity_id FROM customer_entity WHERE email='EMAIL');
 -- leave renewal_date in the future; leave group_id=6

-- RENEWAL_PENDING
UPDATE ahy_caliber_nation_membership SET status='renewal_pending', updated_at=NOW()
 WHERE customer_id=(SELECT entity_id FROM customer_entity WHERE email='EMAIL');
UPDATE customer_entity SET group_id=1 WHERE email='EMAIL';

-- Row #2 / #3 setup (active, auto_renew off, test the cron gap fix)
UPDATE ahy_caliber_nation_membership SET status='active', auto_renew=0,
  renewal_date=DATE_SUB(NOW(), INTERVAL 2 DAY), updated_at=NOW()
 WHERE customer_id=(SELECT entity_id FROM customer_entity WHERE email='EMAIL');
```
After any change: `bin/magento indexer:reindex customer_grid && bin/magento cache:flush`.

---

## 2. Pricing Engine — Discount Layer Combinations

The resolver is **additive**: `total discount = base + seller + category + product`,
clamped by the global cap. Test each layer alone, then stacked, then the cap.

### 2a. Gate check (must pass before any layer applies)

| # | Seller ownership | Participation | Blanket value | Expected |
|---|---|---|---|---|
| A | Admin-owned (no seller) | n/a | n/a | Always gated open — base/category/product layers apply normally |
| B | Webkul seller | **Not participating** (`is_enabled=0` or no row) | n/a | **Gate closed** — zero member pricing on this product, regardless of product-level config |
| C | Webkul seller | Participating | `None` / `0` | Gate open; blanket contributes $0; **product-level discount still applies** |
| D | Webkul seller | Participating | `fixed`/`percent` > 0 | Gate open; blanket contributes its own layer |

### 2b. Single-layer isolation (set all other layers to 0/None, test one at a time)

| # | Layer under test | Config | Product regular price | Expected member price |
|---|---|---|---|---|
| 1 | Base only | `fixed $5` | $30 | $25 |
| 2 | Base only | `percent 10%` | $30 | $27 |
| 3 | Seller only | `fixed $5` (current: seller 17102) | $30 | $25 |
| 4 | Seller only | `percent 15%` | $30 | $25.50 |
| 5 | Category only | `fixed $3` (needs a rule created — none exist currently) | $30 | $27 |
| 6 | Product only | `percent 10%` (current: Aditya Simple Product) | $30 | $27 |
| 7 | Product only | `fixed $2` | $30 | $28 |
| 8 | Product, but **toggle disabled** (`caliber_member_discount_enabled=0`) | value still set | $30 | **$30 — no discount** (pause-without-losing-value behavior) |

### 2c. Stacking (the real-world case — reproduces the bug fixed this session)

| # | Layers active | Regular | Expected member price | Verified this session? |
|---|---|---|---|---|
| 9 | Seller `fixed $5` + Product `percent 10%` | $30 | $30 − $5 − $3 = **$22** | ✅ Yes — this exact combo caught the "cart shows $25, PDP shows $22" bug |
| 10 | Base `fixed $2` + Seller `fixed $5` + Product `percent 10%` | $30 | $30 − $2 − $5 − $3 = **$20** | Not yet — set base to $2 and test |
| 11 | Base + Seller + Category + Product all active | $30 | Sum of all four, then capped | Not yet — needs a category rule created |

### 2d. Global cap (currently `percent 50%`)

| # | Scenario | Raw discount | Cap ($30 × 50% = $15) | Expected |
|---|---|---|---|---|
| 12 | Stack exceeds cap: Seller `fixed $10` + Product `percent 30%` = $19 off | $19 | $15 | Member price = **$15**, `capped=true` — verify UI doesn't show a negative/over-discounted price |
| 13 | Stack under cap (current live combo, $8 off) | $8 | $15 | Member price = **$22**, `capped=false` |
| 14 | Set cap to `fixed $5` instead of percent, same stack | $8 | $5 | Member price = **$25** — confirms cap TYPE (not just value) is read correctly |

### 2e. Layer removed mid-session (regression checklist — fixed this session)

| # | Scenario | Expected on next refresh |
|---|---|---|
| 15 | Active member has $22 in cart; admin disables product's member discount | Price recalculates to whatever remains (e.g. $25 from seller-only) — **not frozen at $22** |
| 16 | Active member has discounted price in cart; membership itself expires/cancels before checkout | Price reverts to full $30 |
| 17 | Seller flips from Participating → Not participating while member has product in cart | Price reverts to full regular price (gate closes) |

---

## 3. Free Gift (Amasty Rule 185) Combination Matrix

Rule 185 = "Buy 1 product, get 1 Free Everest Decal." Requires (both fixed this
session): (a) qualifying-item condition excludes **both** the membership SKU and the
gift's own SKU, (b) customer group 6 included in the rule's targeting.

| # | Cart contents | Customer group | Expected gift? |
|---|---|---|---|
| 1 | Real product only | 1 (guest/general) | Yes |
| 2 | Real product only | 6 (active member) | **Yes** (fixed this session — was previously never added for members) |
| 3 | Real product + membership | 1 or 6 | Yes (real product qualifies) |
| 4 | Membership only (no real product) | any | **No** (neither membership nor gift SKU qualifies) |
| 5 | Real product + membership, then real product removed → cart is membership + gift only | 6 | Gift should be **removed** (was the original reported bug — verify it now drops correctly) |
| 6 | Real product, member reactivates mid-session (expired → active via cron/renewal) | 6 | Gift must **survive** the membership-line removal (verify `RemoveActiveMemberMembership`'s pre-dispatch timing doesn't collide with Amasty) |

---

## 4. Price Consistency Cross-Check (PDP ⇄ Cart ⇄ Checkout)

For **every** combination in §2, verify the SAME member price appears in all three
places — this is exactly the class of bug found this session (cart used a
lightweight product object missing custom attributes).

| Location | What it should show |
|---|---|
| PDP teaser ("Members pay: $X") | Resolver output using full `ProductRepository` load |
| Cart page line price | Must match PDP exactly |
| Checkout review page | Must match PDP exactly |
| Checkout page after a mid-session config change + refresh | Must match the **newly current** config, not a frozen stale value (§2e) |

---

## 5. Signup / Join Form Copy States

| # | Viewer | Expected heading | Expected form heading/button |
|---|---|---|---|
| 1 | Guest (not logged in) | START YOUR MEMBERSHIP | "Create Your Account" / "Create Account & Continue" |
| 2 | Logged in, never a member | JOIN CALIBER NATION | "Complete Your Membership" / "Join & Continue to Checkout" |
| 3 | Logged in, expired/cancelled/renewal_pending (`isReturning`) | WELCOME BACK | "Renew Your Membership" / "Renew & Continue to Checkout" |
| 4 | Logged in, win-back eligible (30+ days lapsed) | WELCOME BACK (win-back price shown) | Same as #3, price = winback ($99.99 × 0.80 = $79.99) |
| 5 | Logged in, active member | YOUR MEMBERSHIP IS ACTIVE | Membership card + "View My Membership" |
| 6 | Name field starts with a digit (any state) | — | Inline error, submit blocked |
| 7 | Logged-in user edits name/email fields | — | Fields are `readonly` — edits are visually locked and correctly have no effect |

---

## 6. Payment Method Restriction & Save-Card Matrix

| # | Cart contents | Save-card checkbox | Expected |
|---|---|---|---|
| 1 | Membership in cart | n/a | Only credit-card / vault payment methods shown; PayPal etc. hidden |
| 2 | Membership in cart | Checked | Order places → `payment_token_id` set → `auto_renew=1` |
| 3 | Membership in cart | Unchecked | Order places → `payment_token_id=null` → `auto_renew=0` (opt-out honored, not forced on) |
| 4 | No membership in cart | n/a | All normal payment methods available |

---

## 7. Trial / Bonus Months Matrix

| # | Trial toggle at signup | Trial toggle at next renewal | Months granted |
|---|---|---|---|
| 1 | ON | — | 13 (12 + 1 bonus) at signup |
| 2 | ON at signup, OFF by renewal | OFF | Renewal grants **12** only (bonus does not persist per-member, it's a live global read) |
| 3 | OFF at signup | ON by renewal | Renewal grants **13** (bonus re-applies) |
| 4 | ON throughout | ON | Every renewal grants 13 — confirms bonus recurs indefinitely while the switch stays on (documented quirk, not a bug fix target) |

---

## 8. Master Program Switch

| # | `caliber_nation/general/enabled` | Expected |
|---|---|---|
| 1 | No | No signup, no member pricing, no payment restriction, no crons, all banners/CTAs hidden, existing membership records untouched |
| 2 | Yes (re-enabled) | Everything resumes exactly where it left off — no data loss, no forced re-activation |

---

## 9. Regression Checklist — bugs found & fixed this session

Re-verify these specifically before considering the module test-complete; each was a
real bug caught during this testing round, not theoretical:

- [ ] Cart/checkout price matches PDP price for every discount combination (§2/§4)
- [ ] Free gift is not lost when the qualifying product is removed and only membership+gift remain (§3.5)
- [ ] Free gift auto-adds for an **active member** (group 6), not just guests/general (§3.2)
- [ ] Rule 185's "Found" condition excludes both `caliber-nation-annual` AND `FREE Everest Decal` SKUs (see Cart-Price-Rule-Changes.md)
- [ ] Stale `custom_price` clears when member goes inactive (§2e.16)
- [ ] Stale `custom_price` clears when a still-active member's discount source is disabled (§2e.15)
- [ ] Seller participation gate blocks ALL pricing for non-participating sellers, but a `None`/`0` blanket does NOT block product-level discounts (§2a.C)
- [ ] `active` + `auto_renew=0` memberships expire once `renewal_date` passes (§1 row 2/3) — previously never transitioned
- [ ] Logged-in join form shows contextual copy (Renew/Complete/Active), never "Create Your Account" for someone who already has an account (§5)
- [ ] Logged-in name/email fields are `readonly` and edits have no effect (§5.7)

---

## 10. Testing Gotchas (not bugs — client-side caching behavior)

- **Mini-cart** (header cart icon) is a client-cached customer-data section — it only
  refreshes on specific actions (`checkout/cart/add`, `/delete`, `/updatePost`, sidebar
  qty update, order placement). A raw DB status flip or a page reload alone will NOT
  refresh it. Always verify pricing on the **actual cart/checkout page**, not just the
  mini-cart, when testing via SQL.
- **Header member name / savings pill / PDP "member" flag** are the same kind of
  cached section — stale after a DB-only status flip until a real login/logout or
  triggering action occurs.
- **`AutoAddMembershipForExpired` only fires on the `customer_login` event.** Flipping
  a customer to `expired` via SQL while they're already logged in will NOT auto-add
  the membership to their cart — you must log them out and back in (or manually
  invoke the add-to-cart logic) to see that specific behavior.
- After any DB-level membership/group change, always run:
  `bin/magento indexer:reindex customer_grid && bin/magento cache:flush`
