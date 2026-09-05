# Caliber Nation — Pending Implementation

> Snapshot: 2026-07-20. Lists only what remains. Everything not listed here is implemented and verified. Legend: ◑ built, live-verification pending · ❌ not started.

---

## ◑ Built, live-verification pending

- **Member pricing in order & subscription emails** — order-email savings **total** line + welcome/renewal savings vars built; **per-line savings in the order email deferred** (needs an items-template override). Verify rendering via Mageplaza Email Log (emails stay disabled on local).
- **Cancellation email** — built + toggle-gated (`cancellation/send_email`); confirm it **renders** correctly via the Email Log.
- **"Average savings" messaging block** — built on the landing page (store-wide, cache-safe, hides at $0); confirm copy/threshold once real order data accrues.
- **Allow membership + physical products together** — code confirms no cart rule blocks mixing (only card-only payment enforced); not yet re-verified end-to-end with a placed mixed order.

---

## ◑ Partial / needs finishing

- **Prompt expired members to renew across touchpoints** — only the **cart** prompt exists. Not shown on account dashboard, PDP/catalog, header, or post-login.
- **Auto-add reliability tweak** — works on `customer_login`; optional hardening via `checkout_cart_load_after` pending if the item ever fails to appear on first login.
- **Signup during cart & checkout** and **during account registration** — partial (CTAs / layout only).

---

## ❌ Not started

### Search & filtering
- **Membership-related filtering** (scope unclear — to define). *(Member pricing in Klevu search results shipped with the PLP work; re-confirm it covers the search-results page.)*

### Early access (deferred)
- Early-access flags for products / sellers / customers.
- Admin-configurable early-bird timing & messaging. *(Early-bird messaging text field is already prepped in admin config.)*
- Early-bird badges on eligible products.

### Seller import & affiliate
- **Adjust import process** to support Caliber participation + pricing (add member-discount columns to Magento/Webkul product import).
- **Affiliate-tracking compatibility** for the membership product (attribution on the custom membership flow; decide renewal-commission policy).

### Landing & content
- **Revamp Caliber membership landing page** (updated scope).
- **Update FAQs and savings section.**

### Compliance (business/legal decision required)
- **US renewal-confirmation / advance-notice rule** — cancellation flow is built, but the US Automatic Renewal Law advance-notice email (send N days before each auto-renew) is not. Needs a business decision on which states, how many days, and notice content before implementation.

---

## Notes
- Biggest remaining clusters: **early access** (whole area), **import + affiliate**, **landing revamp + FAQs**, and the **US advance-notice** compliance rule.
- Most "◑ built" items just need a live email-render pass via the Email Log.
