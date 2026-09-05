# Caliber Nation — Feature Testing Guide

Test account for this pass: **doyax86544@mediseat.com** (`customer_id = 17203`, group 1, no
membership row — a clean slate).

Verified against the live environment on 2026-08-31. Every "Status" below was checked in
code, not taken from the spec.

---

## Environment reference

### Test data

| Thing | Value |
|---|---|
| Membership product | `caliber-nation-annual` — id **317834**, virtual, $99.99 |
| Simple product, seller 17102 (`fixed $20`) | `aditya-simple-30` — id **317835**, $30.00 |
| Simple product, seller 16659 (`fixed $5`) | `877060000116-1` — id **272557**, $39.99 |
| Configurable, seller 16659 | id **272555** (⚠️ see §3 — member pricing does not work on configurables) |
| Member customer group | **6** |

### Live config

| Setting | Value |
|---|---|
| `general/enabled` | 1 |
| `membership/price` | 99.99 |
| `membership/trial_enabled` | **0** (bonus months off) |
| `membership/max_renewal_attempts` | 3 |
| `pricing/enabled` | 1 |
| `pricing/cap_type` / `cap_value` | percent / **70** |
| `winback/enabled` | 1 |
| `winback/days_threshold` | **30** |
| `winback/discount_type` / `value` | percent / **20** |
| `early_access/enabled` | **0** |
| `early_cancellation/refund_window_days` | 30 |

### Shell helpers

```bash
# DB
MYSQL='/Applications/MAMP/Library/bin/mysql -h127.0.0.1 -P3306 -udev1 -pz33409s7275B67u2 dev1'

# PHP (the PATH php is 8.4 and fatals on Magento bootstrap)
PHP='/Applications/MAMP/bin/php/php8.1.13/bin/php -d memory_limit=2G'
```

### The single most useful query — full member state

```sql
SELECT m.entity_id, c.email, m.status, m.auto_renew, m.payment_token_id,
       m.start_date, m.renewal_date, m.is_trial, m.lifetime_savings,
       DATEDIFF(NOW(), m.renewal_date) AS days_since_renewal_date,
       c.group_id
FROM ahy_caliber_nation_membership m
JOIN customer_entity c ON c.entity_id = m.customer_id
WHERE c.email = 'doyax86544@mediseat.com';
```

### ⚠️ Two things that will waste your time if you forget them

1. **Cron is not running** (413 pending jobs, last success 2026-07-20). Every cron-driven
   behaviour — renewals, expiry, lifecycle emails, win-back — must be triggered manually:
   ```bash
   $PHP bin/magento cron:run --group=default
   ```
2. **Browser localStorage caches customer sections.** After any SQL change to a
   membership, the header savings pill / PDP teaser / mini-cart keep the old state. Hard-refresh
   (Cmd+Shift+R) or clear `mage-cache-storage`. A DB-only flip is invisible until you do.
3. **Emails are disabled by design on this instance.** Check
   **Marketing → Email Log** (Mageplaza SMTP), not a real inbox.

---

## 1. Core Membership Platform

| # | What to test | How | Expect |
|---|---|---|---|
| 1.1 | Membership is optional | Log out. Add `aditya-simple-30` to cart, check out as guest | Order completes, no membership required |
| 1.2 | Membership is a purchasable virtual product | Admin → Catalog → Products → search `caliber-nation-annual` | Type **Virtual**, $99.99, Not Visible Individually, qty 999999 |
| 1.3 | Added independently | Landing page → Join → complete signup | Cart contains only the membership |
| 1.4 | Added alongside physical products | Add `aditya-simple-30`, then join via cart banner | Both lines in one order |
| 1.5 | Benefits require login | Log out, view a member-priced PDP | Shows "Members pay $X" + **Join Caliber Nation** link, not the member price |
| 1.6 | No tax on membership | Checkout with only the membership | Tax row **$0.00** (tax_class_id = 0) |

**Signup entry points — check each renders:**

| Entry point | Where | Status |
|---|---|---|
| Landing page | `/caliber-nation` | ✅ |
| Cart | cart page banner "Members save more… Join & Save" | ✅ |
| My Account | Caliber Membership → STATE D "You're Not a Member Yet" | ✅ |
| Account creation | `/customer/account/create` → "Join & Save" opens modal | ✅ |
| Product pages | PDP member-price teaser → "Join Caliber Nation" | ✅ |
| Header prompt | header savings pill (members only) | ⚠️ member-only; no join prompt for guests |
| Checkout | — | ❌ **not implemented** (see §6.5) |

**Verify the order and its membership link:**

```sql
SELECT o.increment_id, o.customer_email, oi.sku, oi.price, o.tax_amount, o.grand_total
FROM sales_order o
JOIN sales_order_item oi ON oi.order_id = o.entity_id
WHERE o.customer_email = 'doyax86544@mediseat.com'
ORDER BY o.entity_id DESC;
```

---

## 2. Membership Payment, Subscription & Renewal

### 2.1 Annual tier only
Signup form offers one plan. `MembershipInterface::VALID_TIERS` contains only `annual`.

### 2.2 Bonus month ("trial") — 13 at signup, 12 at renewal

**Enable:** Stores → Config → Ahy → Caliber Nation Settings → Membership Settings →
Enable Bonus Month(s) = **Yes**, Bonus Months = **1**.

Sign up, then:

```sql
SELECT start_date, renewal_date, is_trial,
       TIMESTAMPDIFF(MONTH, start_date, renewal_date) AS months_granted
FROM ahy_caliber_nation_membership
WHERE customer_id = 17203;
-- expect months_granted = 13, is_trial = 1
```

Then force a renewal (§2.7) and re-check: the renewal must add **12**, not 13.

> This was a bug fixed on 2026-08-31 — the bonus was previously re-granted on every
> renewal. Worth explicitly re-testing.
>
> **Known gap:** the bonus is also granted on **rejoin**, so cancel→rejoin can farm bonus
> months. Only matters if the bonus goes live.

**Turn it back off after testing.**

### 2.3 Admin-configurable price
Change `membership/price` to 89.99 → signup form and cart show $89.99.
⚠️ Changing config does **not** re-price the existing product record — the product keeps
the price it was created with.

### 2.4 Card saved + auto-renewal
During signup checkout, tick **"Save this card securely for future purchases and annual
membership auto-renewal"**.

```sql
SELECT m.payment_token_id, m.auto_renew, t.is_active, t.is_visible,
       JSON_EXTRACT(t.details, '$.maskedCC') AS masked,
       JSON_EXTRACT(t.details, '$.expirationDate') AS expires
FROM ahy_caliber_nation_membership m
LEFT JOIN vault_payment_token t ON t.entity_id = m.payment_token_id
WHERE m.customer_id = 17203;
```

Invariant: **auto_renew = 1 only ever with a usable bound card.** Not saving a card gives
`auto_renew = 0` and `payment_token_id = NULL`.

### 2.5 Non-card payments disabled with membership in cart
Membership in cart → checkout → only Credit Card / saved cards offered. PayPal, Venmo,
Apple Pay hidden (`RestrictPaymentMethods` on `payment_method_is_active`).
Control: cart *without* the membership → other methods reappear.

### 2.6 Status lifecycle

Set each state directly, then check the account page and PDP pricing:

```sql
-- ACTIVE
UPDATE ahy_caliber_nation_membership
   SET status='active', auto_renew=1, renewal_date=DATE_ADD(NOW(), INTERVAL 12 MONTH)
 WHERE customer_id=17203;
UPDATE customer_entity SET group_id=6 WHERE entity_id=17203;

-- EXPIRED
UPDATE ahy_caliber_nation_membership
   SET status='expired', auto_renew=0, renewal_date=DATE_SUB(NOW(), INTERVAL 1 DAY)
 WHERE customer_id=17203;
UPDATE customer_entity SET group_id=1 WHERE entity_id=17203;

-- RENEWAL PENDING (charge failed, still retrying)
UPDATE ahy_caliber_nation_membership
   SET status='renewal_pending', auto_renew=1, renewal_date=DATE_SUB(NOW(), INTERVAL 1 DAY)
 WHERE customer_id=17203;
UPDATE customer_entity SET group_id=1 WHERE entity_id=17203;

-- CANCELLED (paid-through — benefits CONTINUE)
UPDATE ahy_caliber_nation_membership
   SET status='cancelled', auto_renew=0, renewal_date=DATE_ADD(NOW(), INTERVAL 6 MONTH)
 WHERE customer_id=17203;
UPDATE customer_entity SET group_id=6 WHERE entity_id=17203;
```

| Status | Benefits? | Account panel |
|---|---|---|
| `active` | ✅ yes | STATE A — Membership Details |
| `cancelled` + future renewal_date | ✅ **yes** (paid-through) | STATE C — "Your Benefits Are Still Active" + Undo Cancellation |
| `cancelled` + past renewal_date | ❌ no | STATE C — "We'd Love You Back" + Rejoin |
| `renewal_pending` | ❌ no | STATE B — "RENEWAL FAILED" / "Renew Now" |
| `expired` | ❌ no | STATE B — "MEMBERSHIP EXPIRED" / "Renew Membership" |

Entitlement truth: `active` **OR** (`cancelled` AND `renewal_date > NOW()`).

### 2.7 Renewal + receipt email

Force a renewal due today, then run cron:

```sql
UPDATE ahy_caliber_nation_membership
   SET status='active', auto_renew=1, renewal_date=NOW()
 WHERE customer_id=17203;
```

```bash
$PHP bin/magento cron:run --group=default
```

```sql
-- charge attempts
SELECT * FROM ahy_caliber_nation_renewal_log
WHERE membership_id=(SELECT entity_id FROM ahy_caliber_nation_membership WHERE customer_id=17203)
ORDER BY entity_id DESC;

-- renewal_date must have moved +12 months
SELECT status, renewal_date FROM ahy_caliber_nation_membership WHERE customer_id=17203;
```

Receipt: **Marketing → Email Log** → template `caliber_nation_renewal`.
Faster alternative: Admin → Caliber Nation → Memberships → row → **Charge Now**.

**Extension only after successful payment** — verify by forcing a failure (see §2.8);
`renewal_date` must not move.

### 2.8 Failed renewal → 3 strikes → expired

`max_renewal_attempts = 3`; the cron retries daily. Simulate by inserting failures:

```sql
SET @mid = (SELECT entity_id FROM ahy_caliber_nation_membership WHERE customer_id=17203);
INSERT INTO ahy_caliber_nation_renewal_log
  (membership_id, customer_id, status, amount, error_message, created_at)
VALUES (@mid, 17203, 'failed', 99.99, 'simulated decline', NOW()),
       (@mid, 17203, 'failed', 99.99, 'simulated decline', NOW());
```

With 2 failures logged at/after `renewal_date`, the next failure is #3 → status becomes
`expired`, group reverts to 1, expiry email sent. Under 3 → stays `renewal_pending`.

### 2.9 "No reminder emails prior to renewal" — ⚠️ **spec conflict**

Your list says no pre-renewal reminders, but a reminder **is implemented and enabled**:
`emails/renewal_reminder_enabled = 1`, `renewal_reminder_days = 7`. The cron emails
active auto-renewing members 7 days out (`caliber_nation_renewal_reminder`).

**Decision needed:** disable it, or update the spec. To disable:
Stores → Config → … → Email Settings → Renewal Reminder = No.

### 2.10 Self-service cancellation
My Account → Caliber Membership → **Cancel Membership**.
Confirm text must state the paid-through date: *"You keep all your member benefits until
&lt;date&gt; …"*. Then verify `status='cancelled'`, `auto_renew=0`, **`renewal_date`
unchanged**, group still 6.

### 2.11 Auto-renew toggle
Toggle off → `auto_renew=0` **and** `payment_token_id=NULL` (invariant: no auto-renew, no
bound card). Toggle on with no saved card → prompted to add one; with cards → picker.

### 2.12 Renewal date + payment method shown
Account panel shows Plan, Member Since, **Renews On / Expires On**, **Payment Method**
(`VISA •••• 1111`, `Exp 07/2032`), Total Savings. A soft-deleted or expired card must show
**"Not set"**, not a stale card.

---

## 3. Member Pricing & Marketplace Participation

### 3.1 Where member pricing appears

| Surface | Status |
|---|---|
| Product Detail Page | ✅ |
| Product Listing Page | ✅ via Klevu overlay JS |
| Cart & Checkout | ✅ `ApplyMemberPrice` → `setCustomPrice` |
| Order confirmation page | ✅ member congrats + perks |
| Search results | ⚠️ **unverified** — Klevu-powered; needs its own check |
| Order / subscription emails | ⚠️ **unverified** |

### 3.2 The additive model

Four layers, each computed off the **original** price, summed, then capped:

```
membership base + seller + category + product  →  clamped by cap (70%)
```

Worked example, `aditya-simple-30` ($30, seller 17102 = fixed $20):

```
seller  -$20.00
                → member price $10.00
```

Verify without touching the browser:

```bash
$PHP -r '
require "app/bootstrap.php";
$b = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER); $om = $b->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode("frontend");
$r = $om->get(\Ahy\CaliberNation\Model\Service\Pricing\MemberPriceResolver::class);
$p = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById(317835);
echo json_encode($r->resolveForProduct($p)->toArray(), JSON_PRETTY_PRINT), "\n";'
```

### 3.3 Seller participation is a GATE

Admin → Caliber Nation → Seller Participation.

| Seller state | Result |
|---|---|
| Not in the grid / disabled | **No member pricing at all** — even category/product rules are skipped |
| Enabled, discount type **None** | Gate open, no seller layer; product/category discounts still apply |
| Enabled with percent/fixed | Gate open + seller layer contributes |
| **Admin-owned product** (no seller) | **Never gated** — always eligible |

```sql
SELECT p.seller_id, u.shop_title, p.is_enabled, p.discount_type, p.discount_value
FROM ahy_caliber_nation_seller_participation p
LEFT JOIN marketplace_userdata u ON u.seller_id = p.seller_id AND u.shop_title IS NOT NULL;
```

⚠️ Only **2 of 219** sellers participate, and **53% of the catalogue (102,667 products) is
admin-owned** and ungated. A category rule looks broken if you test it on a
non-participating seller's product — pick admin-owned or seller 16659 / 17102 products.

### 3.4 Category level discounts
Admin → Caliber Nation → **Category Level Discounts** → Add Rule. Existing rule: category
**1040 (Air Guns)**, fixed $1.
Multiple matching category rules **sum**. Product-scope is *not* available here — set it on
the product's own "Member Discount" fields.

### 3.5 Product level discount
Product edit → **Caliber Nation Member Pricing** → Enable = Yes, type, value.
Enable = No **pauses** it without losing the numbers.

### 3.6 Stacking with cart rules — coupon applies to the **member** price

$100 product, $15 member discount, 20% coupon:

```
$100 → member $85 → coupon 20% × 85 = $17 → pays $68
```

(*not* 20% of $100.) Mechanism: `setCustomPrice()` runs in
`sales_quote_collect_totals_before`, and the rule engine reads `getCalculationPrice()`.

⚠️ **The 70% cap does NOT include coupons.** Member layers cap at 70%; a coupon then applies
on top, uncapped. $100 → $30 (capped) → 20% coupon → **$24 = 76% off**. There is no
combined ceiling.

### 3.7 ❌ Configurable products — member pricing does NOT work

A configurable's `price` attribute is **0**, so the resolver's `$regularPrice <= 0` guard
returns "no discount" before any layer runs.

```sql
-- 6,851 of 7,083 configurables have price 0/NULL
SELECT type_id, COUNT(*) total,
       SUM(CASE WHEN d.value IS NULL OR d.value=0 THEN 1 ELSE 0 END) AS zero_price
FROM catalog_product_entity e
LEFT JOIN catalog_product_entity_decimal d ON d.entity_id=e.entity_id AND d.store_id=0
  AND d.attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4)
GROUP BY type_id;
```

**Do not test member pricing on configurables** — it will correctly show nothing. Plan to
fix it: `docs/Configurable-Member-Pricing-Plan.md`. Use **simple** products only.

### 3.8 ❌ Affiliate discount compatibility — **not implemented**
No affiliate code anywhere in the module. Nothing to test.

---

## 4. Membership Savings Visibility

### 4.1 Header total-savings pill
Members only. Shows *"You've saved $66.50 as a member"*.
Requires a hard refresh after SQL changes (localStorage).

### 4.2 Per-order savings recorded

```sql
SELECT os.order_id, os.order_item_id, os.product_id, os.seller_id,
       os.regular_price, os.member_price, os.paid_price, os.savings, os.applied_rules
FROM ahy_caliber_nation_order_savings os
WHERE os.customer_id = 17203 ORDER BY os.entity_id DESC;
```

`member_price` is stored **pre-coupon** so membership value and coupon value stay separable.
Free promo-gift lines are excluded (they'd otherwise inflate savings).

### 4.3 Cumulative lifetime savings
`lifetime_savings` accumulates and is **never reset** — it survives expiry, cancellation and
rejoin.

```sql
SELECT
  (SELECT ROUND(SUM(savings),2) FROM ahy_caliber_nation_order_savings WHERE customer_id=17203) AS sum_of_orders,
  (SELECT lifetime_savings FROM ahy_caliber_nation_membership WHERE customer_id=17203) AS lifetime;
```

⚠️ **These two will not always match, and that is expected.** On a fresh account they should
agree, but they diverge legitimately when:
- `lifetime_savings` was adjusted by hand (yave: ledger 41.50 vs lifetime 66.50 — the result
  of an earlier correction when the free-gift line was excluded from savings);
- an order was placed before the savings ledger existed.

For a NEW account like 17203, treat a mismatch as a bug. For pre-existing accounts, compare
per order instead of in aggregate.

### 4.4 Membership Overview in My Account
Status · Renews/Expires On · Total Savings · Payment Method · Auto-renew toggle · digital
membership card.

### 4.5 Admin-configurable messaging
Stores → Config → … → **Messaging**: savings message, average-savings intro + figure,
renewal prompt heading/body.
⚠️ `average_savings_static = 50` is a **hardcoded marketing number**, not computed.

---

## 5. Early Access / Early Bird — ⚠️ **partially built, cannot be tested end to end**

**What exists:**

| Piece | Status |
|---|---|
| Config group `caliber_nation/early_access/*` | ✅ enabled flag, member/public start dates, member + non-member badge text, show-public-date |
| Seller columns `is_early_access`, `early_access_member_start_at`, `early_access_public_start_at` | ✅ in DB |
| `messaging/earlybird_message` | ✅ (empty) |
| **Service / observer / template that enforces or displays it** | ❌ **none** |
| Product-level early-access attribute | ❌ none |

`early_access/enabled = 0`, and no PHP outside `Config.php` references it.

**So:** there are no badges, no alerts, no gating, and no notifications yet. The plumbing is
in place for whoever builds the behaviour.

⚠️ **Early-bird access is nonetheless advertised on 19 customer-facing surfaces** — landing
page perks, join banner, signup panel, renewal popup, account overview, rejoin panel,
checkout success, and the welcome / expiry / win-back emails. Until the feature ships,
that's a promise with nothing behind it. Decide before demoing: build it, or pull the copy.

**Testable today:** only that the config fields save.

---

## 6. Ex-Member Renewal & Incentive Logic

### 6.1 Expired members identified
Set expired (§2.6), then confirm: PDP shows regular price + teaser, header pill gone,
account panel STATE B.

### 6.2 Renewal prompts

| Location | Status |
|---|---|
| Cart banner | ✅ |
| Cart popup (once per session) | ✅ |
| PLP banner | ✅ |
| Account page STATE B | ✅ |
| Email campaigns (expiry / win-back) | ✅ |
| Product pages (PDP) | ⚠️ teaser only, not a renewal prompt |
| Checkout | ❌ not implemented |

All "Renew" CTAs land on `/caliber-nation/?scrollTo=signup` and smooth-scroll to the form.

### 6.3 Auto-add membership for expired members
`AutoAddMembershipForExpired` fires on **`customer_login`** only — not on page view. So:
set expired → **log out → log back in** → membership is in the cart.

### 6.4 ⭐ Day-31 win-back incentive pricing

Config: `winback/enabled=1`, `days_threshold=30`, `discount_type=percent`,
`discount_value=20` → $99.99 → **$79.99**.

Eligibility is `status='expired'` **AND** `DATEDIFF(NOW(), renewal_date) >= 30`.

**Day 30 — NOT yet eligible (control):**
```sql
UPDATE ahy_caliber_nation_membership
   SET status='expired', auto_renew=0,
       renewal_date = DATE_SUB(NOW(), INTERVAL 30 DAY)
 WHERE customer_id = 17203;
UPDATE customer_entity SET group_id=1 WHERE entity_id=17203;
```
Expect: **$99.99**, no win-back messaging.

**Day 31 — eligible:**
```sql
UPDATE ahy_caliber_nation_membership
   SET status='expired', auto_renew=0,
       renewal_date = DATE_SUB(NOW(), INTERVAL 31 DAY)
 WHERE customer_id = 17203;
UPDATE customer_entity SET group_id=1 WHERE entity_id=17203;
```
Expect: signup form shows **$79.99**, cart line for the membership is **$79.99**, cart/PLP
prompt reads *"Come back for a special price of $79.99"*.

**Confirm eligibility + the exact price:**
```sql
SELECT c.email, m.status, m.renewal_date,
       DATEDIFF(NOW(), m.renewal_date) AS days_expired,
       CASE WHEN m.status='expired'
             AND DATEDIFF(NOW(), m.renewal_date) >= 30
            THEN 'WINBACK ELIGIBLE' ELSE 'not eligible' END AS eligibility,
       ROUND(99.99 * (1 - 20/100), 2) AS expected_winback_price
FROM ahy_caliber_nation_membership m
JOIN customer_entity c ON c.entity_id = m.customer_id
WHERE c.email = 'doyax86544@mediseat.com';
```

Also test **fixed** type — set `discount_type=fixed`, `discount_value=79.99`: with fixed,
the value **is the final price**, not an amount off.

Win-back email: set `winback_email_sent_at = NULL`, run cron, check Email Log for
`caliber_nation_winback`.

```sql
UPDATE ahy_caliber_nation_membership SET winback_email_sent_at = NULL WHERE customer_id=17203;
```

### 6.5 ❌ "Prompted to renew during checkout before order placement" — **not implemented**
No checkout-stage renewal prompt exists. Prompts stop at the cart.

---

## 7. Account Creation, Login & Guest Handling

| # | Test | Expect |
|---|---|---|
| 7.1 | Guest signup via landing page | Account created, auto-logged-in, membership linked, redirected to checkout |
| 7.2 | Session transitions to logged-in | Header shows the name immediately after signup |
| 7.3 | Signup during account creation | `/customer/account/create` → "Join & Save" → modal with the same form |
| 7.4 | Membership details require login | `/caliber-nation/account` while logged out → redirected to login |
| 7.5 | Name validation | First/last name cannot **start** with a digit (`123Test` rejected, `Test123` accepted) |
| 7.6 | Logged-in fields readonly | First name, last name, email all readonly for logged-in users |
| 7.7 | Duplicate prevention | Signing up with an existing email → "log in to continue" |

```sql
SELECT c.entity_id, c.email, c.created_in, c.group_id, m.status, m.start_date
FROM customer_entity c
LEFT JOIN ahy_caliber_nation_membership m ON m.customer_id = c.entity_id
WHERE c.email = 'doyax86544@mediseat.com';
```

---

## 8. Admin & Configuration Capabilities

**Stores → Configuration → Ahy → Caliber Nation Settings**

| Group | Key settings |
|---|---|
| General | master Enable switch, membership SKU, member group id |
| Membership Settings | price, bonus months, **max auto-renewal attempts** |
| Email Settings | sender identity |
| Member Pricing | enable, base discount type/value, **global cap** type/value |
| Win-back Discount | enable, days threshold, discount type/value |
| Messaging | savings, average savings, renewal prompt, early-bird copy |
| Landing Page Stats | avg savings/yr, monthly equivalent, max discount % |
| Early Access | enable, member/public start, badge text |
| Early Cancellation | enable, refund window days |
| Cancellation Handling | send email, confirmation message |
| Lifecycle Emails | expiry, renewal reminder (+days), win-back |

**Caliber Nation menu:** Memberships · Seller Participation · Category Level Discounts ·
Early Cancellations

**Master switch test:** General → Enable = **No** → every storefront banner, signup form
and account panel disappears; pricing goes inert; crons stop. Existing records untouched
and resume when re-enabled. *This is the single best "kill switch" demo.*

**Admin row actions** on Memberships: Charge Now · Cancel · Set Active · Set Expired.

**Grid label checks (recently changed):**
- Seller Participation → **Seller Name** column populated (not just IDs)
- Discount Type → `-- None (no discount) --`
- Category Level Discounts → **Category** shows the name ("Air Guns"), Applies To / Discount
  Type / Active show labels, not raw `category` / `fixed` / `1`

⚠️ **Seller import adjustments** (§8 of your list) — not verified; no import code found in
the module.

---

## 9. Landing Page Revamp

| # | Test | Expect |
|---|---|---|
| 9.1 | Perks section | **6 cards**: Caliber Discounts · Early-Bird Notice · Free Gift on Qualifying Orders · Cancel Anytime · Savings Across 1,000's of Sellers · See Every Dollar You Save |
| 9.2 | No stale perks | No "free priority shipping" anywhere (removed sitewide); no events/venues or eat-play-travel cards |
| 9.3 | Membership tier | One card only — Annual, $99.99/year, "Best Value" badge, red CTA |
| 9.4 | Savings section | "By the numbers" band: `$3,500+/yr`, `$8.33/mth`, `90%` — **all admin-configurable** |
| 9.5 | Monthly figure auto-derives | Clear Monthly Equivalent in admin → shows price ÷ 12 |
| 9.6 | FAQs | No shipping claims in answers |
| 9.7 | Session-based routing | Guest → signup form with password fields · Logged-in non-member → "JOIN CALIBER NATION", readonly identity fields · Logged-in lapsed → "WELCOME BACK" + renew copy · Active member → "YOUR MEMBERSHIP IS ACTIVE" |
| 9.8 | CTA scroll | Plan card CTA and all Renew CTAs smooth-scroll to the form |

```sql
-- landing stat config
SELECT path, value FROM core_config_data WHERE path LIKE 'caliber_nation/landing/%';
```

---

## Summary — features that CANNOT be fully tested

| Feature | Status |
|---|---|
| Early access / early bird (§5) | ⚠️ config + schema only; no behaviour. **Advertised on 19 surfaces.** |
| Member pricing on configurables (§3.7) | ❌ broken — 6,851 products affected |
| Affiliate discount compatibility (§3.8) | ❌ not implemented |
| Checkout renewal prompt (§6.5) | ❌ not implemented |
| Checkout signup entry point (§1) | ❌ not implemented |
| Combined discount cap (§3.6) | ❌ no ceiling across member + coupon |
| Search-results member pricing (§3.1) | ⚠️ unverified |
| Member pricing in order emails (§3.1) | ⚠️ unverified |
| Seller import adjustments (§8) | ⚠️ unverified |
| "No pre-renewal reminders" (§2.9) | ⚠️ spec conflict — reminder IS enabled |

## Reset the test account

```sql
DELETE FROM ahy_caliber_nation_order_savings WHERE customer_id = 17203;
DELETE FROM ahy_caliber_nation_activity_log  WHERE customer_id = 17203;
DELETE FROM ahy_caliber_nation_renewal_log   WHERE customer_id = 17203;
DELETE FROM ahy_caliber_nation_refund_request WHERE customer_id = 17203;
DELETE FROM ahy_caliber_nation_membership    WHERE customer_id = 17203;
UPDATE customer_entity SET group_id = 1 WHERE entity_id = 17203;
```
Then hard-refresh the browser to clear cached customer sections.
