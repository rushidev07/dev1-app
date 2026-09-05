# Ahy_DiscountAlert — Module Documentation

**Version:** 1.2.0
**Compatibility:** Magento 2.4.5 CE · PHP 8.1+
**Author:** Afzal Sayed — AHY Tech

> **Sections 3 and 4 describe the pre-1.2 class layout.** Version 1.2 restructured the
> module; see [section 14](#14-v12--architecture-refactor) for the current file list and
> what replaced what.

---

## Table of Contents

1. [Purpose & Business Context](#1-purpose--business-context)
2. [How It Works — High-Level Flow](#2-how-it-works--high-level-flow)
3. [File & Folder Structure](#3-file--folder-structure)
4. [File-by-File Breakdown](#4-file-by-file-breakdown)
   - [registration.php](#registrationphp)
   - [composer.json](#composerjson)
   - [etc/module.xml](#etcmodulexml)
   - [etc/acl.xml](#etcaclxml)
   - [etc/config.xml](#etcconfigxml)
   - [etc/crontab.xml](#etccrontabxml)
   - [etc/email_templates.xml](#etcemail_templatesxml)
   - [etc/di.xml](#etcdixml)
   - [etc/adminhtml/system.xml](#etcadminhtmlsystemxml)
   - [Model/Config/Source/CronFrequency.php](#modelconfigsourcecronfrequencyphp)
   - [Helper/Config.php](#helperconfigphp)
   - [Cron/SendDiscountAlert.php](#cronsenddiscountalertphp)
   - [Service/DiscountProductCollector.php](#servicediscountproductcollectorphp)
   - [Service/DiscountAlertEmailSender.php](#servicediscountalertemailsenderphp)
   - [Mail/TransportBuilder.php](#mailtransportbuilderphp)
   - [view/adminhtml/email/discount_alert.html](#viewadminhtmlemaildiscount_alerthtml)
5. [Database — EAV Architecture Explained](#5-database--eav-architecture-explained)
6. [Email System — Deep Dive](#6-email-system--deep-dive)
7. [Cron Scheduling — How Admin Config Drives It](#7-cron-scheduling--how-admin-config-drives-it)
8. [Coding Practices & Standards](#8-coding-practices--standards)
9. [Integration Guide — How to Deploy](#9-integration-guide--how-to-deploy)
10. [Admin Configuration Guide](#10-admin-configuration-guide)
11. [Testing & Verification](#11-testing--verification)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Purpose & Business Context

The `Ahy_DiscountAlert` module solves a specific operational need: **automatically notify the buying/merchandising team when products are being sold at dangerously deep discounts.**

On Everest.com, products have a `regular_price` and an optional `special_price`. When `special_price` is active and the discount exceeds a configured threshold (default: 50%), it can signal a pricing error, an over-discounted clearance item, or a vendor feed issue. Without automation, this requires someone to manually audit hundreds of product records.

**What the module does:**
- Runs on a configurable cron schedule (weekly by default, up to daily)
- Scans the entire product catalog via a direct SQL query against Magento's EAV tables
- Identifies every product where `(regular_price - special_price) / regular_price > threshold`
- Sends a branded digest email to a configured recipient containing:
  - Total count of flagged products
  - A bracket breakdown (how many products fall into each discount severity range)
  - A table of the top 25 most-discounted products
  - A full CSV attachment of all flagged products for offline review

**Design choice — Interpretation A (full scan, no tracking table):**
The module was intentionally built as a stateless full-catalog scanner. It does not maintain a separate DB table of "known discounted products" or track changes between runs. Every execution scans from scratch. This was chosen because:
- The data source (EAV pricing tables) is always up to date — no risk of stale tracking state
- No schema migration needed; zero DB footprint
- The email is a digest/report, not an "alert on change" notification
- Simpler to debug and verify — the SQL output is the ground truth

---

## 2. How It Works — High-Level Flow

```
Magento Cron Scheduler
        │
        │  reads schedule from core_config_data
        │  (ahy_discount_alert/schedule/cron_expr)
        ▼
Cron\SendDiscountAlert::execute()
        │
        ├── Config::isEnabled()           → bail out if disabled
        ├── Config::getRecipientEmail()   → bail out if empty
        ├── Config::getThreshold()        → e.g. 50.0
        │
        ▼
Service\DiscountProductCollector::getDiscountedProducts(50.0)
        │
        │  Raw SQL via ResourceConnection:
        │  JOIN catalog_product_entity
        │     + catalog_product_entity_decimal (price)
        │     + catalog_product_entity_decimal (special_price)
        │     + catalog_product_entity_varchar (name)
        │  WHERE discount > threshold
        │  ORDER BY discount DESC
        │
        │  Returns: array of rows with sku, name, price,
        │           special_price, discount_pct
        ▼
Service\DiscountAlertEmailSender::send()
        │
        ├── generateCsv()          → UTF-8 BOM CSV string (all products)
        ├── buildBracketRows()     → inline-styled HTML <td> cells
        ├── buildProductsTable()   → inline-styled HTML <tr> rows (top 25)
        │
        ▼
Mail\TransportBuilder  (custom, extends Magento core)
        │
        ├── setTemplateIdentifier('ahy_discount_alert_email')
        ├── setTemplateVars([...])
        ├── addAttachment($csvContent, 'discount_alert_YYYY-MM-DD.csv')
        │
        │  prepareMessage() → parent builds HTML email body
        │                   → injectAttachments() appends CSV as
        │                      MIME part (base64, disposition:attachment)
        ▼
Magento Mail Transport → SMTP (Mageplaza SMTP / Gmail)
        │
        ▼
Recipient inbox — HTML email + CSV attachment
```

---

## 3. File & Folder Structure

```
app/code/Ahy/DiscountAlert/
│
├── registration.php                        # Registers module with Magento's component system
├── composer.json                           # Module metadata, PHP/Magento version constraints
│
├── etc/
│   ├── module.xml                          # Module declaration + load sequence
│   ├── acl.xml                             # Admin role permission resource
│   ├── config.xml                          # Default config values
│   ├── crontab.xml                         # Cron job definition (reads schedule from config)
│   ├── email_templates.xml                 # Registers custom HTML email template
│   ├── di.xml                              # Dependency injection: wires custom TransportBuilder
│   └── adminhtml/
│       └── system.xml                      # Admin config UI (Stores → Configuration → AHY)
│
├── Model/
│   └── Config/
│       └── Source/
│           └── CronFrequency.php           # Dropdown options for cron schedule field
│
├── Helper/
│   └── Config.php                          # Typed accessors for all admin config values
│
├── Cron/
│   └── SendDiscountAlert.php               # Cron entry point — orchestrates the full flow
│
├── Service/
│   ├── DiscountProductCollector.php        # Raw SQL product fetch from EAV tables
│   └── DiscountAlertEmailSender.php        # Builds and sends the email + CSV
│
├── Mail/
│   └── TransportBuilder.php               # Extends core TransportBuilder to support attachments
│
└── view/
    └── adminhtml/
        └── email/
            └── discount_alert.html         # Branded email template (Everest theme, Poppins)
```

---

## 4. File-by-File Breakdown

### `registration.php`

```php
\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Ahy_DiscountAlert',
    __DIR__
);
```

**Purpose:** Every Magento 2 module must have this file. It tells Magento's autoloader and component system that this directory is a module named `Ahy_DiscountAlert`. Without this, the module is invisible to Magento regardless of what else is configured.

**Convention:** The module name follows the `Vendor_ModuleName` pattern — `Ahy` is the vendor (AHY Tech), `DiscountAlert` is the module name.

---

### `composer.json`

**Purpose:** Defines module metadata and dependency constraints for Composer.

**Key decisions:**
- `"type": "magento2-module"` — Composer knows this is a Magento module, not a generic PHP package
- `"php": ">=8.1"` — The module uses PHP 8.1 features (readonly constructor promotion, `never` return type). This prevents installation on incompatible environments
- `"magento/framework": ">=103.0.0"` — Pins to the Magento 2.4.x framework which introduced the modern mail interfaces (`EmailMessageInterface`, `MimeMessageInterface`) used by the custom TransportBuilder
- `autoload.psr-4` maps `Ahy\\DiscountAlert\\` to the module root, enabling PSR-4 class autoloading

---

### `etc/module.xml`

```xml
<module name="Ahy_DiscountAlert" setup_version="1.0.0">
    <sequence>
        <module name="Magento_Config"/>
        <module name="Magento_Store"/>
        <module name="Magento_Catalog"/>
    </sequence>
</module>
```

**Purpose:** Declares the module and specifies its load order relative to other modules.

**Why the sequence matters:**
- `Magento_Config` — must load before us because our `system.xml` extends its configuration section schema and our `Helper/Config` uses `ScopeConfigInterface` which is provided by this module
- `Magento_Store` — our config reads use `ScopeInterface::SCOPE_STORE`, which is defined here
- `Magento_Catalog` — our SQL queries target catalog EAV tables. Ensuring Catalog loads first guarantees its schema and attribute definitions are in place

---

### `etc/acl.xml`

```xml
<resource id="Ahy_DiscountAlert::config"
          title="Discount Alert Configuration"
          sortOrder="100"/>
```

**Purpose:** Defines a permission resource in Magento's ACL (Access Control List) system.

**How it works:** The resource is placed as a child of `Magento_Config::config` (the "Stores → Configuration" node). This means any admin role that has access to "Stores → Configuration" can be given/denied access specifically to the Discount Alert section. The `system.xml` section references this resource via `<resource>Ahy_DiscountAlert::config</resource>`, so Magento enforces the permission check before displaying the config page.

---

### `etc/config.xml`

```xml
<default>
    <ahy_discount_alert>
        <general>
            <enabled>1</enabled>
            <threshold>50</threshold>
            <recipient_email></recipient_email>
        </general>
        <schedule>
            <cron_expr>0 9 * * 1</cron_expr>
        </schedule>
    </ahy_discount_alert>
</default>
```

**Purpose:** Provides default values for all admin config fields. These values are used when no admin override exists in `core_config_data`.

**Key defaults:**
- `enabled = 1` — module is active out of the box after installation
- `threshold = 50` — 50% discount is the baseline alert level
- `recipient_email` — intentionally left blank; the cron will log a warning and skip if this is not set
- `cron_expr = 0 9 * * 1` — every Monday at 9:00 AM UTC (once a week)

The path structure `ahy_discount_alert/general/enabled` directly maps to the section/group/field IDs defined in `system.xml`.

---

### `etc/crontab.xml`

```xml
<job name="ahy_discount_alert"
     instance="Ahy\DiscountAlert\Cron\SendDiscountAlert"
     method="execute">
    <config_path>ahy_discount_alert/schedule/cron_expr</config_path>
</job>
```

**Purpose:** Registers the cron job with Magento's cron scheduler.

**The critical design here — `<config_path>` instead of `<schedule>`:**

The standard approach for hardcoded cron schedules is:
```xml
<schedule><cron_expr>0 9 * * 1</cron_expr></schedule>
```

Instead, `<config_path>` tells Magento: *"read the cron expression dynamically from this config path at runtime."* This is what enables the admin dropdown to control the schedule. When an admin changes the frequency in `Stores → Configuration`, Magento updates `core_config_data` with the new expression, and the next cron tick picks it up automatically — zero code deployments needed.

---

### `etc/email_templates.xml`

```xml
<template id="ahy_discount_alert_email"
          label="Discount Alert Notification"
          file="discount_alert.html"
          type="html"
          module="Ahy_DiscountAlert"
          area="adminhtml"/>
```

**Purpose:** Registers the HTML email template with Magento's email template system.

**Why `area="adminhtml"`:** This email is operational/internal — it notifies store admins/buyers, not customers. Using `adminhtml` area means the template is rendered using the admin store context, which is correct since it accesses admin-scope configuration and does not need storefront layout.

**The `id`** (`ahy_discount_alert_email`) is the handle used by `DiscountAlertEmailSender` when calling `setTemplateIdentifier()` on the TransportBuilder.

---

### `etc/di.xml`

```xml
<type name="Ahy\DiscountAlert\Service\DiscountAlertEmailSender">
    <arguments>
        <argument name="transportBuilder" xsi:type="object">
            Ahy\DiscountAlert\Mail\TransportBuilder
        </argument>
    </arguments>
</type>
```

**Purpose:** Wires the custom `TransportBuilder` into `DiscountAlertEmailSender` via Magento's Dependency Injection system.

**Why scoped injection instead of a global preference:**

A global preference would replace Magento's `TransportBuilder` for the entire application:
```xml
<!-- DO NOT DO THIS — replaces TransportBuilder everywhere -->
<preference for="Magento\Framework\Mail\Template\TransportBuilder"
            type="Ahy\DiscountAlert\Mail\TransportBuilder"/>
```

That would affect every email sent by every module. Instead, the scoped `<type><arguments>` approach injects our custom `TransportBuilder` only when `DiscountAlertEmailSender` is instantiated — isolated, safe, and zero side effects on other email functionality.

After adding this file, `php bin/magento setup:di:compile` must be run to regenerate interceptor classes.

---

### `etc/adminhtml/system.xml`

**Purpose:** Defines the admin configuration UI under `Stores → Configuration → AHY → Discount Alert`.

**Structure:**
- **Tab:** `ahy` (creates the "Ahy" tab in the left sidebar)
- **Section:** `ahy_discount_alert` (the config page itself)
- **Group: General** — three fields:
  - `enabled` (Yes/No select using Magento's built-in `Yesno` source model)
  - `threshold` (text input with built-in numeric + non-negative validators)
  - `recipient_email` (text input with built-in email format validator)
- **Group: Schedule** — one field:
  - `cron_expr` (dropdown using `CronFrequency` source model — maps labels to cron expressions)

**`showInWebsite="0" showInStore="0"`:** Config is Global scope only. This is intentional — the alert is a single operational notification, not something that varies per website or store view.

---

### `Model/Config/Source/CronFrequency.php`

```php
class CronFrequency implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '0 9 * * 1',     'label' => 'Once a week (Monday at 9:00 AM)'],
            ['value' => '0 9 * * 1,4',   'label' => 'Twice a week (Mon & Thu at 9:00 AM)'],
            ['value' => '0 9 * * 1,3,5', 'label' => 'Three times a week (Mon, Wed, Fri at 9:00 AM)'],
            ['value' => '0 9 * * *',     'label' => 'Daily (9:00 AM)'],
        ];
    }
}
```

**Purpose:** Provides the dropdown options for the Schedule → Alert Frequency admin field.

**Design decision — store cron expressions as values:**
The `value` stored in `core_config_data` is the raw cron expression (e.g., `0 9 * * 1`), not a label or key. Since `crontab.xml` reads the value directly from config via `<config_path>`, no translation layer is needed — the expression goes straight into Magento's cron scheduler. This keeps the system simple with no additional mapping logic.

**Implements `OptionSourceInterface`:** This is the correct Magento 2 interface for dropdown source models. It requires a single method `toOptionArray()` that returns `[['value' => ..., 'label' => ...], ...]`.

---

### `Helper/Config.php`

```php
class Config extends AbstractHelper
{
    private const XML_PATH_ENABLED         = 'ahy_discount_alert/general/enabled';
    private const XML_PATH_THRESHOLD       = 'ahy_discount_alert/general/threshold';
    private const XML_PATH_RECIPIENT_EMAIL = 'ahy_discount_alert/general/recipient_email';

    public function isEnabled(): bool { ... }
    public function getThreshold(): float { ... }
    public function getRecipientEmail(): string { ... }
}
```

**Purpose:** Provides strongly-typed, single-responsibility accessors to all admin configuration values. Centralises all config path strings as `private const` — if a path ever changes, there is exactly one place to update it.

**Why a Helper and not reading config directly in the Cron class:**
- Keeps `SendDiscountAlert` (the cron class) free of config path string literals
- Enables easy unit testing by mocking `Config` independently
- Makes the config structure self-documenting — reading `$this->config->getThreshold()` is clearer than `$this->scopeConfig->getValue('ahy_discount_alert/general/threshold', ...)`

**Return types are explicitly cast:** `(float)`, `(string)`, `isSetFlag()` (returns bool) — Magento's `scopeConfig` returns mixed types; explicit casting prevents type errors in callers.

---

### `Cron/SendDiscountAlert.php`

**Purpose:** The cron job entry point. Orchestrates the entire flow but contains zero business logic itself.

**Constructor dependencies (PHP 8.1 readonly promotion):**
```php
public function __construct(
    private readonly Config $config,
    private readonly DiscountProductCollector $collector,
    private readonly DiscountAlertEmailSender $emailSender,
    private readonly LoggerInterface $logger
) {}
```

**Execution logic:**
1. Check `isEnabled()` — silently return if disabled (no log noise)
2. Check `getRecipientEmail()` — log a warning and return if empty (the operator needs to know it's misconfigured)
3. Call `$collector->getDiscountedProducts($threshold)` inside a try/catch — a DB failure should not crash the cron process
4. Guard against zero results — log info and return, no email sent
5. Call `$emailSender->send()` inside a separate try/catch — an email send failure is logged but does not throw

**Why separate try/catch blocks:** A product collection failure (DB issue) is a different failure class than an email send failure (SMTP issue). Logging them separately makes diagnosis easier.

**Single Responsibility Principle:** This class only decides *whether* to run and *what data to pass*. It does not know how products are fetched or how emails are built.

---

### `Service/DiscountProductCollector.php`

**Purpose:** Fetches all products exceeding the discount threshold directly from the database.

**Why raw SQL instead of Magento's product collection/repository:**

Magento's `ProductRepository` and `Collection` load full product models with all attributes — hundreds of EAV table reads per product. For 200,000+ products this would exhaust memory and take minutes. The raw SQL approach joins exactly three tables and fetches only the four columns needed (sku, name, price, special_price) in a single query. On a 200k product catalog the difference is seconds vs. never finishing.

**Dynamic EAV attribute ID resolution:**
```php
private function resolveAttributeIds($connection, array $codes): array
{
    $attributeIds = $connection->fetchPairs(
        $connection->select()
            ->from($eavTable, ['attribute_code', 'attribute_id'])
            ->where('entity_type_id = ?', self::CATALOG_PRODUCT_ENTITY_TYPE_ID)
            ->where('attribute_code IN (?)', $codes)
    );
    ...
}
```

Attribute IDs in Magento's EAV system are auto-increment integers that differ between installations. Hardcoding them (e.g., `attribute_id = 77`) would break on any other Magento instance. The resolver fetches the IDs at runtime from `eav_attribute` filtered by `attribute_code` and `entity_type_id = 4` (catalog product). It also guards against missing attributes by throwing a `RuntimeException` with a clear message.

**The core SQL logic:**
```sql
WHERE sp.value > 0               -- special_price must exist and be positive
  AND sp.value < p.value         -- must actually be cheaper than regular price
  AND (p.value - sp.value) / p.value > {fraction}  -- discount exceeds threshold
ORDER BY (p.value - sp.value) / p.value DESC        -- highest discount first
```

**`discount_pct` computed in PHP, not SQL:**
MySQL's floating-point arithmetic can produce precision artefacts. Computing `round(($price - $special) / $price * 100, 2)` in PHP gives consistent, testable results.

---

### `Service/DiscountAlertEmailSender.php`

**Purpose:** Builds all email content (HTML table rows, bracket cells, CSV) and sends the email via Magento's mail system.

**`send()` method — the coordinator:**
```php
public function send(string $recipientEmail, array $products, float $threshold): void
{
    $totalCount  = count($products);
    $topProducts = array_slice($products, 0, self::TOP_N);  // top 25
    $csvContent  = $this->generateCsv($products, $threshold);
    $fileName    = sprintf('discount_alert_%s.csv', gmdate('Y-m-d'));

    $this->transportBuilder
        ->setTemplateIdentifier(self::TEMPLATE_ID)
        ->setTemplateOptions([...])
        ->setTemplateVars([
            'bracket_rows'   => $this->buildBracketRows($products, $threshold),
            'products_table' => $this->buildProductsTable($topProducts),
            'total_count'    => $totalCount,
            'shown_count'    => count($topProducts),
            'threshold'      => $threshold,
            'csv_filename'   => $fileName,
            'run_date'       => gmdate('D, d M Y'),
        ])
        ->setFromByScope('general', Store::DEFAULT_STORE_ID)
        ->addTo($recipientEmail)
        ->addAttachment($csvContent, $fileName, 'text/csv')
        ->getTransport()
        ->sendMessage();
}
```

**`generateCsv()` — Excel-compatible CSV:**
- Opens a `php://memory` stream (no temp file on disk)
- Writes a UTF-8 BOM (`\xEF\xBB\xBF`) as the first three bytes — this is required for Excel on Windows/Mac to correctly detect UTF-8 encoding without the user having to use the import wizard
- Writes header row + one row per product: Rank, SKU, Name, Regular Price, Sale Price, Discount %, Bracket
- Rewinds and reads the stream, then closes it

**`buildBracketRows()` — discount severity distribution:**
Counts products into five brackets (90%+, 80–90%, 70–80%, 60–70%, threshold–60%) and returns pre-rendered `<td>` cells. The lowest bracket label is dynamic — it reflects the configured threshold (e.g., "50–60%") rather than being hardcoded.

**`buildProductsTable()` — top-25 HTML rows:**
Generates inline-styled `<tr>` rows. Inline styles are mandatory for email clients (Gmail strips `<style>` blocks). `htmlspecialchars()` is applied after `html_entity_decode()` to handle names stored with HTML entities in the DB (e.g., `&reg;` → `®`).

**HTML entity decoding explained:**
Some product names in the database are stored with HTML entities: `Realtree Fishing&reg; Camo Shirt`. Applying only `htmlspecialchars()` would double-encode: `&reg;` → `&amp;reg;` which renders as literal `&reg;` in the email. The correct pipeline is:
```php
html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')  // &reg; → ®
htmlspecialchars($name, ENT_QUOTES, 'UTF-8')                 // ® → ® (untouched)
```

---

### `Mail/TransportBuilder.php`

**Purpose:** Extends Magento's core `TransportBuilder` to add MIME attachment support. Magento 2.4.x's built-in `TransportBuilder` has no public API for attachments.

**The inheritance problem:**
Magento's `TransportBuilder` stores the built `EmailMessageInterface` in a `protected $message` property, but the MIME part factories (`MimePartInterfaceFactory`, `MimeMessageInterfaceFactory`, `EmailMessageInterfaceFactory`) are injected as `private` properties — inaccessible from child classes.

**Solution — `objectManager->get()` for factory access:**
```php
$mimePartFactory    = $this->objectManager->get(MimePartInterfaceFactory::class);
$mimeMessageFactory = $this->objectManager->get(MimeMessageInterfaceFactory::class);
$emailMsgFactory    = $this->objectManager->get(EmailMessageInterfaceFactory::class);
```

Using `objectManager->get()` directly is generally discouraged in Magento because it bypasses DI and makes dependencies invisible. However, this is a justified exception: the parent class's private properties are not injectable from a child constructor, and using `objectManager` in a private method of a class that is itself properly DI-wired is the correct Magento-idiomatic solution for this specific pattern.

**`injectAttachments()` — how the attachment is added:**
```php
// 1. Get existing body parts (the HTML email body)
$parts = $current->getMessageBody()->getParts();

// 2. Append CSV as a new MIME part
$parts[] = $mimePartFactory->create([
    'content'     => $att['content'],
    'type'        => 'text/csv',
    'fileName'    => $att['fileName'],
    'disposition' => 'attachment',
    'encoding'    => 'base64',
]);

// 3. Rebuild the EmailMessage with the updated parts
$this->message = $emailMsgFactory->create([
    'body'    => $mimeMessageFactory->create(['parts' => $parts]),
    'to'      => $current->getTo(),
    'subject' => $current->getHeaders()['Subject'],
    // + from, cc, bcc, replyTo if present
]);
```

The `EmailMessage` is immutable (Magento's design), so adding a part requires constructing a new message instance with all original data plus the new attachment — it cannot be mutated in place.

**`reset()` override:**
Clears `$this->attachments = []` and calls `parent::reset()`. Without this, attachments would persist across multiple `send()` calls in the same PHP process (e.g., if the sender is called in a loop).

---

### `view/adminhtml/email/discount_alert.html`

**Purpose:** The HTML email template. Rendered by Magento's email engine with template variable substitution.

**Template variable syntax — why `|raw` is required:**
```html
{{var bracket_rows|raw}}
{{var products_table|raw}}
```
Magento's email engine applies `|escape` (HTML-escaping) to ALL `{{var}}` substitutions by default. Without `|raw`, the pre-rendered HTML strings from PHP (`<td>`, `<tr>`, etc.) would be output as escaped text — the browser would display the literal HTML tags. The `|raw` filter bypasses escaping for variables that are intentionally pre-built HTML.

**Why pre-render HTML in PHP, not in the template:**
The bracket distribution and product table require PHP logic (counting, looping, conditional coloring) that Magento's simple template engine cannot express. The template handles static layout structure; PHP handles dynamic content generation.

**Inline styles — mandatory for email:**
Every style is applied as a `style=""` attribute directly on each element. Email clients (especially Gmail) strip `<style>` blocks from the `<head>`. Inline styles are the only reliable cross-client approach.

**Poppins font loading:**
```html
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
      rel="stylesheet">
```
Google Fonts load in: Gmail (web), Apple Mail, most modern email clients. Fallback to `Arial, Helvetica, sans-serif` covers Outlook and clients that block external resources.

**Brand palette:**
| Token | Hex | Used for |
|---|---|---|
| Navy | `#0f2b45` | Header bar, footer, large numbers, titles |
| Amber | `#c8622a` | Brand label, discount % highlights |
| Warm white | `#f0ede8` | Outer email background |
| Off-white | `#f9f7f4` | Section alternating backgrounds |
| Border | `#e4dfd8` | All table and card borders |

---

## 5. Database — EAV Architecture Explained

Magento uses the **Entity-Attribute-Value (EAV)** pattern for product data. Instead of one wide table with a column per attribute, attributes are stored in separate typed tables.

**Relevant tables:**

| Table | Stores |
|---|---|
| `catalog_product_entity` | Core product row (entity_id, sku, type_id) |
| `catalog_product_entity_decimal` | Decimal attributes: price, special_price, weight |
| `catalog_product_entity_varchar` | Short text attributes: name, url_key, color |
| `eav_attribute` | Attribute registry: attribute_code → attribute_id mapping |

**Why `entity_type_id = 4`:**
EAV is used across multiple Magento entities (products, categories, customers). The `entity_type_id` discriminates between them. Products are always type 4 in a standard Magento installation.

**Why `store_id = 0`:**
Store ID 0 is the "default/global" scope. Attributes set at this scope apply to all stores unless overridden at a specific store view (store_id = 1, 2, ...). The module reads from `store_id = 0` to get the base pricing data consistently regardless of how many store views exist.

**Verification query for phpMyAdmin:**
```sql
SELECT
    cpe.sku,
    n.value                                             AS name,
    p.value                                             AS regular_price,
    sp.value                                            AS sale_price,
    ROUND((p.value - sp.value) / p.value * 100, 2)     AS discount_pct
FROM catalog_product_entity cpe
JOIN catalog_product_entity_decimal p
    ON p.entity_id = cpe.entity_id AND p.store_id = 0
    AND p.attribute_id = (SELECT attribute_id FROM eav_attribute
                          WHERE attribute_code = 'price' AND entity_type_id = 4)
JOIN catalog_product_entity_decimal sp
    ON sp.entity_id = cpe.entity_id AND sp.store_id = 0
    AND sp.attribute_id = (SELECT attribute_id FROM eav_attribute
                           WHERE attribute_code = 'special_price' AND entity_type_id = 4)
LEFT JOIN catalog_product_entity_varchar n
    ON n.entity_id = cpe.entity_id AND n.store_id = 0
    AND n.attribute_id = (SELECT attribute_id FROM eav_attribute
                          WHERE attribute_code = 'name' AND entity_type_id = 4)
WHERE sp.value > 0
  AND sp.value < p.value
  AND (p.value - sp.value) / p.value > 0.50
ORDER BY (p.value - sp.value) / p.value DESC;
```
Change `0.50` to match the configured threshold.

---

## 6. Email System — Deep Dive

**Flow: template → variable substitution → MIME message → SMTP**

1. `setTemplateIdentifier('ahy_discount_alert_email')` — Magento looks up the template in `email_templates.xml`, finds `discount_alert.html` in `view/adminhtml/email/`
2. `setTemplateVars([...])` — these become available as `{{var key}}` in the template
3. `setFromByScope('general', ...)` — reads the store's configured "general" sender identity (name + email) from `Stores → Configuration → Store Email Addresses`
4. `getTransport()->sendMessage()` — Magento calls `prepareMessage()` which triggers our override and injects the CSV attachment before the message is handed to the mail transport
5. The mail transport (Mageplaza SMTP) sends via Gmail's SMTP relay

**SMTP setup (Mageplaza SMTP module):**
- Host: `smtp.gmail.com` (must be trimmed, no whitespace)
- Port: 587 (STARTTLS) or 465 (SSL)
- Authentication: Gmail address + App Password (not account password)
- App Passwords require 2FA to be enabled on the Gmail account

**Common SMTP pitfall — whitespace in host field:**
If the host field in the DB contains leading/trailing spaces (e.g., ` smtp.gmail.com  `), the Laminas mail library throws: `"The input appears to be a DNS hostname but cannot match TLD against known list"`. Fix:
```sql
UPDATE core_config_data
SET value = TRIM(value)
WHERE path IN ('smtp/configuration_option/host', 'smtp/configuration_option/port');
```

---

## 7. Cron Scheduling — How Admin Config Drives It

The standard Magento cron pattern hardcodes a schedule in `crontab.xml`. This module uses a more flexible pattern:

```xml
<config_path>ahy_discount_alert/schedule/cron_expr</config_path>
```

**What happens at runtime:**
1. Magento's `cron.php` runs every minute (triggered by the server's system crontab: `* * * * * php /path/to/magento/bin/magento cron:run`)
2. For each registered job, Magento checks whether to use a hardcoded `<schedule>` or read from `<config_path>`
3. For this job, it reads the value of `ahy_discount_alert/schedule/cron_expr` from `core_config_data`
4. The value is a standard cron expression (e.g., `0 9 * * 1,4`)
5. Magento evaluates whether the current time matches and fires the job if so

**Changing the schedule:**
Admin changes the dropdown → Magento saves the new cron expression to `core_config_data` → next cron minute the scheduler reads the new expression → takes effect immediately. No deployment, no CLI, no code change.

**Cron expression reference:**

| Admin label | Expression | Meaning |
|---|---|---|
| Once a week | `0 9 * * 1` | Monday 9:00 AM |
| Twice a week | `0 9 * * 1,4` | Mon & Thu 9:00 AM |
| Three times a week | `0 9 * * 1,3,5` | Mon, Wed, Fri 9:00 AM |
| Daily | `0 9 * * *` | Every day 9:00 AM |

All times are UTC. Adjust if the team's timezone requires a different UTC offset.

---

## 8. Coding Practices & Standards

**PHP 8.1 constructor property promotion:**
```php
public function __construct(
    private readonly Config $config,
    private readonly DiscountProductCollector $collector,
    ...
) {}
```
Using `readonly` ensures dependencies cannot be reassigned after construction, making the class state predictable and immutable. This replaces the verbose Magento 2.3-era pattern of declaring private properties separately and assigning in the constructor body.

**`declare(strict_types=1)` on every PHP file:**
Enables strict type checking. Passing a string where a float is expected throws a `TypeError` immediately instead of silently coercing. This catches configuration errors (e.g., misconfigured threshold) at the point of misuse.

**Global function calls prefixed with `\`:**
```php
\count($products)
\array_slice($products, 0, 25)
\gmdate('Y-m-d')
```
In a namespaced file, PHP first looks for a function in the current namespace before falling back to global. The `\` prefix forces direct lookup in the global namespace — slightly faster, and eliminates any risk of namespace collision with a local function of the same name.

**No unnecessary comments:**
Code is written to be self-documenting. Method names (`getDiscountedProducts`, `buildBracketRows`, `injectAttachments`) describe intent. Comments are only present where something is genuinely non-obvious (e.g., the `objectManager->get()` factory access pattern in `TransportBuilder`).

**Single Responsibility Principle across classes:**
- `Config` — reads config; nothing else
- `DiscountProductCollector` — queries products; nothing else
- `DiscountAlertEmailSender` — builds and sends email; nothing else
- `TransportBuilder` — adds attachment capability; nothing else
- `SendDiscountAlert` — orchestrates flow; no business logic

**No magic strings in production code:**
Config paths are `private const` in `Config.php`. Template ID is `private const TEMPLATE_ID` in `DiscountAlertEmailSender`. Bracket definitions are `private const BRACKETS`. All magic values have one canonical definition.

**HTML entity safety:**
Product names from the database are processed through:
```php
html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')  // decode stored entities first
htmlspecialchars($name, ENT_QUOTES, 'UTF-8')                 // then re-encode safely for HTML output
```
This prevents double-encoding of names that contain `&reg;`, `&trade;`, `&amp;` etc.

**PSR-2 code style:**
Enforced by `.php-cs-fixer.dist.php` at the repo root. Run `php-cs-fixer fix app/code/Ahy/` after any changes.

---

## 9. Integration Guide — How to Deploy

### On a fresh Magento 2.4.5 installation

**Step 1 — Place the module files:**
The module already lives at `app/code/Ahy/DiscountAlert/`. On a new environment, ensure all 16 files are present (see File Structure above).

**Step 2 — Enable the module:**
```bash
php bin/magento module:enable Ahy_DiscountAlert
```

**Step 3 — Run setup upgrade:**
```bash
php bin/magento setup:upgrade
```
This registers the module, makes its config schema available, and seeds `core_config_data` with defaults from `config.xml`.

**Step 4 — Compile DI:**
```bash
php bin/magento setup:di:compile
```
Required because `etc/di.xml` adds a new type argument for `DiscountAlertEmailSender`. Without compilation, the DI container will not know to inject the custom `TransportBuilder`.

**Step 5 — Flush cache:**
```bash
php bin/magento cache:flush
```

**Step 6 — Configure SMTP:**
Install and configure Mageplaza SMTP (or equivalent) under `Stores → Configuration → Mageplaza → SMTP`. Set:
- Host: `smtp.gmail.com` (no spaces)
- Port: `587`
- Authentication: `Login`
- Username: sender Gmail address
- Password: Gmail App Password (generate at myaccount.google.com → Security → App Passwords)

**Step 7 — Configure Discount Alert:**
Navigate to `Stores → Configuration → AHY → Discount Alert`:
- Enable: Yes
- Threshold: e.g., `50`
- Recipient Email: e.g., `buyer@yourcompany.com`
- Frequency: choose from dropdown

**Step 8 — Ensure system crontab is configured:**
```cron
* * * * * php /path/to/magento/bin/magento cron:run 2>&1 | grep -v "^$" >> /var/log/magento-cron.log
```
Without this, Magento cron jobs never fire.

### Manual test (without waiting for cron):
```bash
php -r "
require 'app/bootstrap.php';
\$b = \Magento\Framework\App\Bootstrap::create(BP, \$_SERVER);
\$om = \$b->getObjectManager();
\$om->get(\Magento\Framework\App\State::class)->setAreaCode('crontab');
\$om->create(\Ahy\DiscountAlert\Cron\SendDiscountAlert::class)->execute();
echo 'Done.' . PHP_EOL;
"
```
Run from the Magento root. Check `var/log/system.log` for the result line:
```
[DiscountAlert] Alert sent to recipient@example.com — 1509 product(s) above 50% threshold.
```

---

## 10. Admin Configuration Guide

**Navigation:** `Stores → Configuration → AHY → Discount Alert`

| Field | Path | Description |
|---|---|---|
| Enable Discount Alert | `ahy_discount_alert/general/enabled` | Master on/off switch. If disabled, cron runs but immediately returns. |
| Discount Threshold (%) | `ahy_discount_alert/general/threshold` | Numeric. Products where `(regular - sale) / regular * 100 > threshold` are included. Default: 50. |
| Alert Recipient Email | `ahy_discount_alert/general/recipient_email` | The email address that receives the alert. If empty, cron logs a warning and skips. |
| Alert Frequency | `ahy_discount_alert/schedule/cron_expr` | Dropdown. Stores a raw cron expression. Change takes effect on the next cron minute. Default: Monday 9:00 AM. |

---

## 11. Testing & Verification

**Verify email was sent:**
```bash
grep 'DiscountAlert' var/log/system.log | tail -5
```

**Verify product count matches DB:**
```sql
SELECT COUNT(*) AS flagged
FROM catalog_product_entity_decimal sp
JOIN catalog_product_entity_decimal p
    ON p.entity_id = sp.entity_id AND p.store_id = 0
    AND p.attribute_id = (SELECT attribute_id FROM eav_attribute
                          WHERE attribute_code='price' AND entity_type_id=4)
WHERE sp.store_id = 0
  AND sp.attribute_id = (SELECT attribute_id FROM eav_attribute
                         WHERE attribute_code='special_price' AND entity_type_id=4)
  AND sp.value > 0 AND sp.value < p.value
  AND (p.value - sp.value) / p.value > 0.50;
```
The count must match `total_count` in the email subject line and the row count in the CSV (excluding the header row).

**Verify a specific product:**
```sql
SELECT cpe.sku, n.value AS name, p.value AS regular, sp.value AS sale,
       ROUND((p.value - sp.value) / p.value * 100, 2) AS discount_pct
FROM catalog_product_entity cpe
JOIN catalog_product_entity_decimal p  ON p.entity_id = cpe.entity_id AND p.store_id = 0
    AND p.attribute_id  = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='price' AND entity_type_id=4)
JOIN catalog_product_entity_decimal sp ON sp.entity_id = cpe.entity_id AND sp.store_id = 0
    AND sp.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='special_price' AND entity_type_id=4)
LEFT JOIN catalog_product_entity_varchar n ON n.entity_id = cpe.entity_id AND n.store_id = 0
    AND n.attribute_id  = (SELECT attribute_id FROM eav_attribute WHERE attribute_code='name' AND entity_type_id=4)
WHERE cpe.sku = 'YOUR-SKU-HERE';
```

---

## 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| No email received, no log entry | Cron not running | Check system crontab; run `php bin/magento cron:run` manually |
| Log: "No recipient email configured" | `recipient_email` field is empty in admin | Set it under `Stores → Configuration → AHY → Discount Alert` |
| Log: "Product collection failed" | DB connection issue or missing EAV attributes | Check `var/log/exception.log`; verify `price` and `special_price` attributes exist |
| Email received but HTML tags showing | `|raw` filter missing on template variable | Check `discount_alert.html` — all HTML vars need `{{var name|raw}}` |
| SMTP error: "cannot match TLD against known list" | Whitespace in SMTP host field | Run: `UPDATE core_config_data SET value = TRIM(value) WHERE path LIKE 'smtp/%host%'` |
| Email received but no CSV attached | DI compile not run after adding `etc/di.xml` | Run `php bin/magento setup:di:compile && php bin/magento cache:flush` |
| `discount_pct` bracket counts don't add up to total | Products with exactly 90% discount fall in wrong bracket | Expected — brackets use `>=` lower bound and `<` upper bound; 90% goes to "90%+" bracket |

---

## 13. Review & Disable Workflow (v1.1)

The alert email carries a **Review & Disable Products** button. It does *not* disable
anything on click — it opens an authenticated admin page listing exactly the products
that alert flagged, where the user picks which ones to disable.

### Why not a one-click disable link in the email?

Corporate mail security scanners (Outlook Safe Links, Proofpoint, Mimecast) and mail
clients prefetch every URL in a message. A GET link that disabled products would fire
the moment the mail was delivered. An email link also carries no admin session, so
there would be no ACL check and no admin action log. The button therefore lands on an
admin page; the destructive step is an authenticated POST a human deliberately makes.

### Flow

1. Cron collects flagged products (unchanged).
2. `RunStorage::createRun()` writes the run and its products to
   `ahy_discount_alert_run` / `ahy_discount_alert_run_item`, generating a 32-char
   random token.
3. Cron builds `{admin}/ahy_discountalert/run/view/run_id/<id>/token/<token>/` and
   passes it to the email as the `review_url` template variable.
4. The button renders only when `review_url` is non-empty (`{{depend review_url}}`).
5. Clicking it → admin login (Magento returns the user to the link) → ACL check on
   `Ahy_DiscountAlert::manage` → token check against the stored run.
6. The page lists the run's products with current status, all still-enabled ones
   pre-checked. "Disable Selected Products" POSTs to `run/disable`.
7. `Disable` intersects the submitted IDs with the run's own product IDs, calls
   `Magento\Catalog\Model\Product\Action::updateAttributes()` with
   `status = STATUS_DISABLED` at store 0, stamps `disabled_at` / `disabled_by`, and
   logs the action.

If run persistence fails for any reason, the alert email is still sent — just without
the button.

### New files

| File | Purpose |
|---|---|
| `etc/db_schema.xml` | The two run tables |
| `etc/db_schema_whitelist.json` | Declarative-schema whitelist |
| `etc/adminhtml/routes.xml` | Admin route `ahy_discountalert` |
| `Service/RunStorage.php` | Persist/read runs, token check, mark disabled |
| `Service/AttributeIdResolver.php` | Shared EAV attribute-ID lookup (cached) |
| `Controller/Adminhtml/Run/View.php` | Landing page (GET) |
| `Controller/Adminhtml/Run/Disable.php` | Disable action (POST only) |
| `Block/Adminhtml/Run/Products.php` | Page block |
| `view/adminhtml/layout/ahy_discountalert_run_view.xml` | Page layout |
| `view/adminhtml/templates/run/products.phtml` | Product list + form |

### Security notes

- **Token**: 32-char random string per run, compared with `hash_equals()`. It gates
  *which run* is readable — it is not a substitute for authentication.
- **Admin secret key**: `View::_processUrlKeys()` returns `true` because cron has no
  session and therefore cannot generate a secret key. Admin login and the ACL check
  still apply. `Disable` does **not** override it — it validates form key and secret
  key normally.
- **Scope limiting**: the disable action only ever touches product IDs recorded for
  that run; anything else in the POST is dropped and logged.
- **Rollback**: `status_at_send` stores each product's status at send time, so a bad
  bulk disable can be reversed from the DB.

### New ACL resource

`Ahy_DiscountAlert::manage` ("Discount Alert — Review & Disable Products"), nested
under `Magento_Catalog::catalog`. Admin roles need it granted to use the page.

### Deploying this version

```bash
php bin/magento module:enable Ahy_DiscountAlert
php bin/magento setup:upgrade          # creates the two tables
php bin/magento setup:di:compile       # production mode only
php bin/magento cache:flush
```

---

## 14. v1.2 — Architecture Refactor

Behaviour is unchanged: the same cron schedule flags the same products, sends the same
email with the same CSV attachment, and the review button still opens the same admin page.
What changed is how it is put together.

### Mail (was `Mail/TransportBuilder.php`)

The old builder extended `Magento\Framework\Mail\Template\TransportBuilder`, overrode its
protected methods, read its protected `$message`, and pulled four factories out of the
ObjectManager at runtime. It has been deleted and replaced by:

| Class | Role |
|---|---|
| `Service/Email/AlertMailer.php` | Renders the template and builds/sends the message from injected framework factories only. No inheritance, no ObjectManager. |
| `Service/Email/Envelope.php` | Immutable value object: template, vars, recipients, store, attachments. |
| `Service/Email/Attachment.php` | Immutable value object for an attached file. |

Because the message is now assembled explicitly, the subject line is taken straight from
the rendered template rather than fished out of a header array, and CC/BCC no longer need
special handling.

### CLI (was `test_discount_alert_cron.php`)

The standalone bootstrap script has been deleted. Use the registered command:

```bash
bin/magento ahy:discount-alert:send                      # collect, record and send
bin/magento ahy:discount-alert:send --dry-run            # preview, nothing written or sent
bin/magento ahy:discount-alert:send --dry-run --show=50  # preview with 50 rows
bin/magento ahy:discount-alert:send --threshold=70 --email=me@example.com
bin/magento ahy:discount-alert:send --cc=a@x.com,b@y.com --bcc=c@z.com --limit=100
```

Cron and CLI both call `Service/AlertDispatcher.php`, so the two cannot drift apart.

### Email presentation (was string concatenation in the sender)

`buildBracketRows()` and `buildProductsTable()` are gone. Markup now lives in templates:

| File | Role |
|---|---|
| `Block/Email/BracketSummary.php` + `view/base/templates/email/brackets.phtml` | Distribution cells |
| `Block/Email/ProductsTable.php` + `view/base/templates/email/products_table.phtml` | Product rows |
| `Service/Email/AlertContentRenderer.php` | Renders those blocks to HTML under adminhtml area emulation |

Templates sit under `view/base/` so they resolve identically from cron, CLI and admin.
Prices are formatted with `PriceCurrencyInterface`, so the store's own currency is used
rather than an assumed `$`.

### Discount bands (was two hardcoded copies)

`Model/Discount/BracketProvider.php` is now the only place bands are defined, and it
derives them from the configured threshold: from the threshold up to the next ten, then in
steps of ten, closing with an open-ended top band. At the default threshold of 50 that
reproduces the previous 50–60 / 60–70 / 70–80 / 80–90 / 90%+ exactly; at a threshold of 70
you get three meaningful bands instead of two empty ones and a label reading "70–60%".
The email summary and the CSV `Bracket` column both read from it.

### Configuration (was `Helper/Config.php`)

Replaced by `Model/Config.php` implementing `Api/ConfigInterface`, injecting
`ScopeConfigInterface` directly and reading at **default scope explicitly** — the scope the
fields are actually editable at, and the only unambiguous choice for cron and CLI.

New fields under **Stores → Configuration → Ahy → Discount Alert**:

| Group | Field | Path | Default | Purpose |
|---|---|---|---|---|
| Email Contents | Products Listed in Email | `email/product_limit` | 25 | Rows in the email body (was a hardcoded `TOP_N`) |
| Email Contents | Maximum Products per Run | `email/max_products` | 5000 | Caps collected rows, CSV size and memory; 0 for no limit |
| Review Link | Review Link Lifetime (days) | `security/link_lifetime` | 0 | Days the emailed link stays valid; 0 never expires |

`threshold` and `recipient_email` are now `required-entry`, and the threshold is validated
to the 1–99 range.

### Persistence (was `Service/RunStorage.php`)

Raw SQL in a service has been replaced by a proper model layer:

```
Api/Data/RunInterface.php          Api/Data/RunItemInterface.php
Api/RunRepositoryInterface.php     Model/RunRepository.php
Model/Run.php                      Model/RunItem.php
Model/ResourceModel/Run.php        Model/ResourceModel/RunItem.php        (SQL lives here)
Model/ResourceModel/Run/Collection.php  Model/ResourceModel/RunItem/Collection.php
```

`ahy_discount_alert_run` gains an `item_count` column, so a run knows whether the
collection cap truncated it. The review page and email both say so when it did.

### Review link lifetime

`RunRepository::getByToken()` now enforces the lifetime the error message always claimed
to have. The default is 0 (never expires), so existing behaviour is unchanged until you
set a lifetime.

### Admin page

- Paged at 100 rows, so a run with thousands of products cannot render one enormous page.
- Current product status comes from the catalog product collection in a single query for
  the visible page, rather than a product model per row.
- JavaScript moved to `view/adminhtml/web/js/run-items.js`, wired with `data-mage-init`.
  No inline `<script>` (CSP-safe), and confirmation uses `Magento_Ui/js/modal/confirm`
  instead of `window.confirm`.
- The admin secret-key check is still skipped on the read-only `Run/View` action only —
  cron cannot mint a secret key — and the reasoning is documented in the method. The
  `Run/Disable` action validates form key and secret key normally.

### Catalog query

- Attribute IDs and value tables resolve through `Magento\Eav\Model\Config`, so
  `entity_type_id = 4` and the `catalog_product_entity_*` table names are no longer
  hardcoded.
- The entity link field comes from `MetadataPool`, so the query also holds where Staging
  rewrites the catalog to `row_id`.
- `Zend_Db_Expr` → `Magento\Framework\DB\Sql\Expression`; join conditions are built with
  `quoteInto()`.
- The discount percentage is computed in SQL and the result set is capped, replacing an
  unbounded `fetchAll()` plus a PHP loop.
- `count()` and `getList()` are separate, so the email still reports the true total while
  only the capped rows are loaded.

### CSV

`Service/Csv/DiscountCsvWriter.php` streams rows one at a time to a uniquely-named file
under `var/ahy_discount_alert/` through the `Filesystem` API, reads it once for the
attachment, and deletes it afterwards — instead of building the whole file in
`php://memory`.

### Logging

All logging goes to a dedicated channel, `var/log/ahy_discount_alert.log`, configured with
virtual types in `etc/di.xml`. Handlers catch `\Throwable` (not just `\Exception`, so a PHP
`Error` cannot abort the whole cron group) and pass `['exception' => $e]` so stack traces
are recorded.

### Branding

No domain, logo path or company name is hardcoded any more.
`Model/Email/BrandingProvider.php` reads the transactional email logo from
**Design → Transactional Emails** and the store name from **Store Information**; the
template falls back to the store name when no logo is configured. The Google Fonts
`<link>` has been removed — mail clients strip it, and it was a third-party callout from a
business email.

### Interfaces

`Api/ConfigInterface`, `Api/ProductCollectorInterface`, `Api/RunRepositoryInterface`,
`Api/Data/RunInterface` and `Api/Data/RunItemInterface` are marked `@api` and wired with
preferences in `etc/di.xml`, so the collector or repository can be swapped by another
module without touching this one. The dead `transportBuilder` argument override that the
old `di.xml` contained is gone.

### Still outstanding

Deliberately not addressed in 1.2, from the review list:

- The review page is a purpose-built table rather than a UI Component listing, so it has no
  sorting/filtering/export.
- There is still no admin menu entry or runs grid, so past runs are reachable only through
  the emailed link.
- No unit or integration tests, and no `i18n/en_US.csv`.
- Store-scope price overrides, `special_from_date` / `special_to_date` windows, product
  status/visibility and catalog price rules are still outside what the query considers.

### Upgrading

```bash
bin/magento module:enable Ahy_DiscountAlert
bin/magento setup:upgrade        # adds ahy_discount_alert_run.item_count
bin/magento setup:di:compile     # production mode
bin/magento setup:static-content:deploy   # production mode, for the new admin JS
bin/magento cache:flush
```

If you had customised the `ahy_discount_alert_email` template in the admin, re-check it:
`stored_count`, `truncated`, `logo_url` and `store_name` are new variables, and the logo
block now uses `{{if logo_url}}`.
