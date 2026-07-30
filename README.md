# Burrow for WordPress

Know what your website is actually doing. Burrow connects your WordPress site to [Burrow](https://useburrow.com) and turns form submissions, store orders, and site health into clean, real-time reporting — without you touching a line of code.

## Why Burrow?

- **See every lead the moment it arrives.** Form submissions from the plugins you already use flow straight into Burrow, so you always know which forms are working and which are collecting dust.
- **Understand your revenue.** Orders, line items, refunds, and cancellations are tracked automatically, giving you sales reporting and product-level insight across your store.
- **Set it up in minutes.** A guided wizard detects the plugins on your site, walks you through connecting to Burrow, and lets you pick exactly what to track.
- **Never lose an event.** If Burrow is temporarily unreachable, events queue safely on your site and deliver automatically once the connection returns.
- **Start with history, not a blank slate.** Backfill up to two years (or all time) of past form submissions and orders so your reporting is useful on day one.
- **Privacy by design.** You choose which form fields are shared — everything else stays on your site. Order events never include customer names, emails, or addresses, only an opaque customer token.

## Supported form plugins

Burrow auto-detects whichever of these are active on your site:

- Gravity Forms
- WPForms
- Contact Form 7
- Ninja Forms
- Fluent Forms
- Formidable Forms
- SureForms

For each form you can choose **Count-only** (just the fact that a submission happened) or **Custom fields** (map specific fields you want reported).

## Supported ecommerce plugins

- **WooCommerce** — orders, line items, fulfillment, refunds, cancellations, and optional cart & checkout funnel tracking (add-to-cart, checkout started, abandoned carts, cart recovery, payment failures).
- **SureCart** — orders, purchased items, cancellations, and refunds. (Cart-level funnel events aren't available because the SureCart cart runs on SureCart's hosted platform.)

## How it works

1. Install and activate the plugin — you'll land in the setup wizard.
2. Connect with your Burrow API key ([find it here](https://app.useburrow.com/settings)) and pick your project.
3. Select the integrations the wizard detected and choose what to track.
4. Optionally queue a historical backfill, then watch events arrive in Burrow.

After setup, **Burrow → Settings** lets you adjust integrations any time, and **Burrow → Outbox** shows exactly what has been sent, queued, or retried.

## What gets sent (and what doesn't)

- Form events include the form name, a submission ID, a timestamp, and **only the fields you explicitly map**.
- Order events include order totals, currency, item names and quantities, and shipping region — never customer PII.
- Hourly health pings and a weekly stack snapshot (plugin inventory and update availability) help you keep the site healthy.

## Good to know

- Contact Form 7 doesn't store submissions by default — install [Flamingo](https://wordpress.org/plugins/flamingo/) if you want historical CF7 backfill.
- SureForms and SureCart are tracked from the moment you enable them; historical backfill for these is coming in a future release.

---

## For developers

Contributions and local development:

```bash
composer install   # requires the PHP SDK path repo at ../burrow-sdk/php (useburrow/sdk-php ^0.9.9)
composer test      # PHPUnit suite
./scripts/build-release.sh   # builds dist/burrow-wordpress-plugin.zip with vendor included
```

- Events use deterministic keys (e.g. `forms:<formId>:<submissionId>`) for safe retries and dedupe.
- Delivery is enqueue-first through a durable `{prefix}burrow_outbox` table with exponential backoff.
- Event icons use [Lucide](https://lucide.dev/icons) icon key strings.
- Releases are built by GitHub Actions on `v*` tags.
