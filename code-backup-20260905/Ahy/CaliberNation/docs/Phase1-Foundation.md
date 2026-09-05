# Caliber Nation — Phase 1 Foundation (Implementation Reference)

> **Status:** Implementation-ready reference for Phase 1.
> **Scope of Phase 1:** Module skeleton + the membership database table (current-state spine).
> The Savings Ledger and Renewal/Payment Log are **documented here for reference but NOT built in Phase 1** — they are deferred to the features that depend on them (member pricing and auto-renewal respectively).

---

## 0. Context

Caliber Nation is a paid annual membership program for the Everest (Webkul) marketplace. The full spec spans 9 areas, but seller/pricing rules are **not yet defined**, so Phase 1 builds only the **membership spine** — the foundation everything else attaches to.

This is a **completely fresh, self-contained module `Ahy_CaliberNation`**. The legacy `Ahy/Caliber` module (Yotpo loyalty points, hardcoded customer group `6`) is **not reused in any way** — no code, no dependency, not its group convention. The new module creates and owns its own customer group.

**Design principle for the data layer:** the membership table holds **current state**; history/event data (savings, renewal attempts) lives in **separate child tables** built later.

---

## 1. New Magento Module — `Ahy_CaliberNation`

### 1.1 Purpose
Create the module shell that Magento recognizes and loads in the correct order. No feature logic in this part — purely registration and declaration. Every later step (table, product, observers) hangs off this skeleton.

### 1.2 Directory layout (Phase 1 footprint)
```
app/code/Ahy/CaliberNation/
├── registration.php
├── composer.json
├── docs/
│   └── Phase1-Foundation.md        (this file)
└── etc/
    ├── module.xml
    ├── db_schema.xml               (membership table — Part 2)
    └── di.xml                      (repository preferences — Part 2)
```

### 1.3 `registration.php`
Registers the module directory with Magento's `ComponentRegistrar`. Without it, Magento never discovers the folder. Follows the exact house pattern used across all `Ahy_*` modules:

```php
<?php
/**
 * Copyright © Ahy consulting All rights reserved.
 * See COPYING.txt for license details.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Ahy_CaliberNation', __DIR__);
```

### 1.4 `etc/module.xml`
Declares the module and its **load-order dependencies** via `<sequence>`.

```xml
<?xml version="1.0" ?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Module/etc/module.xsd">
    <module name="Ahy_CaliberNation">
        <sequence>
            <module name="Magento_Catalog"/>
            <module name="Magento_Customer"/>
            <module name="Magento_Sales"/>
            <module name="Magento_Quote"/>
            <module name="Magento_Vault"/>
            <module name="Magento_Payment"/>
            <module name="Magento_Config"/>
            <module name="Ahy_Authorizenet"/>
        </sequence>
    </module>
</config>
```

**Important:** `<sequence>` does **not** make these modules *required* — it controls **load order**, guaranteeing they are initialized before ours so our observers/plugins/config can safely build on top of them.

| Dependency | Why it's sequenced before us |
|------------|------------------------------|
| `Magento_Catalog` | We add a product attribute and create the membership virtual product. |
| `Magento_Customer` | We create a customer group and reassign customers into it. |
| `Magento_Sales` | The activation observer fires on order placement; the refund guard hooks credit memos. |
| `Magento_Quote` | Payment restriction inspects the quote's cart contents. |
| `Magento_Vault` | We store the saved-card token reference for renewal. |
| `Magento_Payment` | Payment-method availability filtering (card-only when membership in cart). |
| `Magento_Config` | Admin system configuration section. |
| `Ahy_Authorizenet` | Our token storage integrates with this custom vault/tokenization layer. |

### 1.5 `composer.json`
Package metadata matching the house convention for `Ahy_*` modules:

```json
{
    "name": "ahy/module-caliber-nation",
    "description": "Caliber Nation membership program for the Everest marketplace.",
    "type": "magento2-module",
    "license": "proprietary",
    "require": {
        "php": ">=8.1"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {
            "Ahy\\CaliberNation\\": ""
        }
    }
}
```

### 1.6 Verification (module shell only)
```bash
bin/magento module:enable Ahy_CaliberNation
bin/magento setup:upgrade
bin/magento cache:flush
bin/magento module:status Ahy_CaliberNation   # expect: enabled
```
Success = module reports **enabled** and all commands run with **no errors**.

---

## 2. Database Schema

Three tables are documented. **Only Table 2.1 is created in Phase 1.** Tables 2.2 and 2.3 are reference designs for later phases.

### 2.1 `ahy_caliber_nation_membership` — **BUILD IN PHASE 1**

The **current-state** record of each customer's membership. One row per customer (see design note below). Declared in `etc/db_schema.xml`.

| Column | Type (db_schema) | Null | Default | Meaning |
|--------|------------------|------|---------|---------|
| `entity_id` | `int` unsigned, identity | no | — | Primary key |
| `customer_id` | `int` unsigned | no | — | The owning customer (FK → `customer_entity.entity_id`) |
| `status` | `varchar(32)` | no | `active` | `active` / `renewal_pending` / `expired` / `cancelled` |
| `tier` | `varchar(32)` | no | `annual` | Membership tier (only `annual` for now; room to grow) |
| `start_date` | `datetime` | yes | `null` | When the membership began |
| `renewal_date` | `datetime` | yes | `null` | When it next renews / expires |
| `auto_renew` | `smallint(1)` | no | `1` | Auto-renew on/off toggle (future self-service control) |
| `payment_token_id` | `int` unsigned | yes | `null` | Saved card used for renewal (FK → `vault_payment_token.entity_id`) |
| `is_trial` | `smallint(1)` | no | `0` | Whether the member is in the trial period |
| `created_at` | `timestamp` | no | `CURRENT_TIMESTAMP` | Row creation time |
| `updated_at` | `timestamp` | no | `CURRENT_TIMESTAMP` on update | Last update time |

**Constraints:**
- **Primary key:** `entity_id`
- **Foreign key:** `customer_id` → `customer_entity.entity_id`, ON DELETE CASCADE
- **Foreign key:** `payment_token_id` → `vault_payment_token.entity_id`, ON DELETE SET NULL
- **Unique key:** `customer_id` (enforces one current membership per customer — see design note)

**Recommended indexes (not columns — for later renewal-cron performance):**
- Index on `status` — cron query "find expired / renewal_pending memberships"
- Index on `renewal_date` — cron query "find memberships due to renew today"

> These indexes add no columns and can be added now or with the renewal cron. Listed here so the decision is explicit.

**Design note — one row per customer (chosen approach):**
This table is the customer's **current** membership state, uniquely keyed on `customer_id`. On renewal or rejoin, the existing row is **updated** (not duplicated). Full history (renewals, savings) lives in the dedicated child tables below. This keeps the spine simple; the activation flow performs a create-or-update.

#### 2.1.a Access layer (part of Phase 1, follows `Ahy_EstateApiIntegration` pattern)
Built alongside the table so code never touches the table directly:
- `Api/Data/MembershipInterface.php` — data contract; **status values defined as constants here** (single source of truth: `STATUS_ACTIVE`, `STATUS_RENEWAL_PENDING`, `STATUS_EXPIRED`, `STATUS_CANCELLED`).
- `Api/MembershipRepositoryInterface.php` — `save()`, `getById()`, `getByCustomerId()`, `delete()`.
- `Model/Membership.php`, `Model/ResourceModel/Membership.php` (+ `Collection`), `Model/MembershipRepository.php` — implementations.
- `etc/di.xml` — `<preference>` entries wiring each interface to its implementation.

---

### 2.2 Savings Ledger — **DEFERRED (reference only)**

**Serves:** Section 4 (Membership Savings Visibility) — *"Cumulative savings tracking per member," "total savings to date," header savings display.*

**Core idea:** every time a member buys at the member price, they "saved" the difference between regular and member price. To show a trustworthy **"total saved to date"**, record each individual saving event — not a running total in one column.

**Why a separate table (one row per saving event):**
- A single `total_savings` column on the membership row **lies when an order is refunded or cancelled** — it can't know which orders contributed, so you can't correctly subtract.
- It can't answer "how much did I save last month?" or power a savings-history view.
- With a ledger, the total is always `SUM(saved_amount)` — provably correct; a refund just adds a reversing (negative) row.

**Proposed shape:**

| Column | Meaning |
|--------|---------|
| `entity_id` | Primary key |
| `customer_id` | The member (links back to membership) |
| `order_id` | Which order produced this saving (FK → `sales_order`) |
| `order_item_id` | Which line item (enables per-product savings) |
| `regular_price` | Price a non-member would have paid |
| `member_price` | Price the member actually paid |
| `saved_amount` | `regular_price − member_price` for that line |
| `created_at` | When it happened |

**Usage:** "Total savings to date" = `SELECT SUM(saved_amount) WHERE customer_id = X`. Powers the header badge and the My Account "Membership Overview." A refund writes a reversing row.

**Why deferred:** depends entirely on **member pricing existing** (Section 3), which is blocked on the undefined seller/pricing rules. No member pricing yet → nothing to record yet.

---

### 2.3 Renewal / Payment Log — **DEFERRED (reference only)**

**Serves:** Section 2 (Payment, Subscription & Renewal) — the lifecycle, *"membership extended only after successful payment,"* renewal receipts, and the `renewal_pending` status.

**Core idea:** auto-renewal will try to charge the saved card on the renewal date. That charge can **succeed, fail, or be retried**. A permanent record of every attempt is needed — for receipts, for support ("why was I charged?"), for retry logic, and to drive the `renewal_pending` state.

**Why a separate table (one row per renewal attempt):**
- The membership row holds only **current state** (`status`, `renewal_date`); it deliberately doesn't remember *how it got there*.
- Renewals recur **over years** — inherently a list of events, i.e. a child table, not columns.
- Failed attempts and retries need their own timestamps and error messages; "tried 3 times, 2 failed" can't be modeled in the parent row.

**Proposed shape:**

| Column | Meaning |
|--------|---------|
| `entity_id` | Primary key |
| `membership_id` | Which membership (FK → `ahy_caliber_nation_membership.entity_id`) |
| `customer_id` | The member |
| `attempted_at` | When the renewal charge was attempted |
| `result` | `success` / `failed` / `retrying` |
| `amount` | Amount charged |
| `payment_token_id` | Which saved card was used (FK → `vault_payment_token`) |
| `order_id` | The renewal order created on success (FK → `sales_order`) |
| `error_message` | Gateway error if it failed |
| `created_at` | Timestamp |

**Usage:** the renewal cron writes a row per attempt. On `success` it extends the membership's `renewal_date` and sends the receipt email. On `failed` it flips the membership to `renewal_pending` and may schedule a retry.

**Why deferred:** the auto-renewal cron is explicitly later-scope. This table arrives with that job.

---

### 2.4 Not a table — Incentive Pricing (Section 6)
Win-back / renewal-incentive pricing is **not stored data** — it is **admin config** (discount amount, the "30-day expired" threshold) plus **runtime logic** at pricing/checkout time. The only data it needs already exists in the membership table: `status` and `renewal_date` tell you how long someone has been expired. **No new table.**

---

## 3. Table Summary

| Table | Spec Section | Phase | Granularity | Role |
|-------|-------------|-------|-------------|------|
| `ahy_caliber_nation_membership` | Core | **Phase 1** | One row per customer | Current membership state |
| Savings Ledger | 4 | Deferred | One row per saving event | Refund-proof savings history |
| Renewal / Payment Log | 2 | Deferred | One row per renewal attempt | Renewal event trail |
| ~~Incentive pricing~~ | 6 | Deferred | *(not a table)* | Config + runtime rule |

---

## 4. Phase 1 Verification (module + table)
```bash
bin/magento module:enable Ahy_CaliberNation
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```
Then confirm:
1. `module:status Ahy_CaliberNation` → **enabled**.
2. Table `ahy_caliber_nation_membership` exists with all 11 columns, the two foreign keys, and the unique key on `customer_id`.
3. A row can be saved and reloaded through `MembershipRepositoryInterface`.
