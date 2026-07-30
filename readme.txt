=== Burrow ===
Contributors: useburrow
Tags: woocommerce reporting, form tracking, ecommerce analytics, event tracking, woocommerce analytics
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track WooCommerce sales, form submissions, and site events automatically. Connects to Burrow for real-time analytics and reporting.

== Description ==

Burrow is a lightweight WordPress plugin that delivers powerful WooCommerce reporting, WooCommerce analytics, and form submission tracking with minimal setup. Connect your site to the Burrow platform and start receiving real-time analytics and reporting on orders, cart activity, and form submissions — all through a reliable, contract-based event schema.

**Features:**

* Minimal setup — a guided onboarding wizard walks you through connection and configuration
* WooCommerce and SureCart analytics: track purchases, order lifecycle events, and revenue automatically
* Form submission tracking for Gravity Forms, WPForms, Contact Form 7, Ninja Forms, Fluent Forms, Formidable Forms, and SureForms
* Resilient queued sync keeps events safe during temporary downtime
* Contract-based event schema for consistent, reliable WooCommerce reporting
* Historical data backfill to populate Burrow with past submissions and orders
* System health monitoring including stack snapshots and heartbeat checks

**Works With:**

* WooCommerce (orders, order lifecycle, line items, revenue tracking)
* SureCart (orders, purchased items, cancellations, refunds)
* Gravity Forms
* WPForms
* Contact Form 7
* Ninja Forms
* Fluent Forms
* Formidable Forms
* SureForms

**Why Burrow?**

Unlike generic analytics plugins, Burrow uses a contract-based event schema that guarantees consistent, structured data — so your WooCommerce reporting and form tracking stay accurate even as your site evolves. A resilient queued sync means no events are lost during downtime, and the guided onboarding wizard gets you from install to insight in minutes.

= Third-Party Service =

This plugin connects to the **Burrow** external service ([useburrow.com](https://useburrow.com)) to transmit event data from your WordPress site. The following data may be sent to Burrow's servers:

* **Onboarding data:** Your site URL, WordPress version, PHP version, and list of active plugins are sent during initial setup to discover and configure your Burrow project.
* **Form submission events:** When configured, form submission metadata (form ID, submission ID, timestamps, and any fields you explicitly map in the contract editor) is sent as structured events. No form data is sent unless you enable tracking for a specific form.
* **WooCommerce events:** When enabled, order metadata (order ID, totals, currency, item counts, shipping method, payment method) is sent. Customer identity is transmitted only as an opaque token — no names, emails, or physical addresses are ever included.
* **System events:** Periodic stack snapshots (WordPress version, PHP version, plugin versions) and heartbeat pings are sent to monitor site health within Burrow.

All data is transmitted over HTTPS to Burrow's API endpoints. An API key (configured during onboarding) authenticates every request.

* Burrow service: [https://useburrow.com](https://useburrow.com)
* Terms of Service: [https://useburrow.com/terms](https://useburrow.com/terms)
* Privacy Policy: [https://useburrow.com/privacy](https://useburrow.com/privacy)

== Installation ==

1. Upload the `burrow` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress. You will be redirected to the onboarding wizard automatically.
3. Enter your Burrow API base URL (defaults to `https://app.useburrow.com`) and API key. You can find your API key at [app.useburrow.com/settings](https://app.useburrow.com/settings).
4. Select the Burrow project to connect to.
5. Choose which integrations to enable (form plugins and/or WooCommerce). The wizard auto-detects installed plugins.
6. Add forms from the selective picker (sorted by 120-day volume) and choose count-only or custom field mappings.
7. Review and sync your contracts to Burrow.
8. Optionally backfill historical data (defaults to two years). After setup, use **Burrow → Settings** for ongoing configuration.

== Frequently Asked Questions ==

= Where do I get a Burrow API key? =

Sign up at [useburrow.com](https://useburrow.com) and find your API key at [app.useburrow.com/settings](https://app.useburrow.com/settings).

= Which form plugins are supported? =

Burrow auto-detects and supports Gravity Forms, WPForms, Contact Form 7, Ninja Forms, Fluent Forms, Formidable Forms, and SureForms. The onboarding wizard only shows plugins that are active on your site.

= Does Burrow work with WooCommerce? =

Yes. Burrow tracks WooCommerce order lifecycle events including order placement, fulfillment, refunds, and cancellations along with line-item detail. Optional funnel tracking covers add-to-cart, checkout, and abandonment events.

= Does Burrow work with SureCart? =

Yes. Burrow tracks SureCart orders, purchased items, cancellations, and refunds. Cart-level funnel events are not available because the SureCart cart runs on SureCart's hosted platform.

= Can I backfill historical data? =

Yes. After completing the onboarding wizard, you can backfill past form submissions and WooCommerce orders from predefined time windows (7/30/90 days, 1 year, 2 years, or all time). The default window is two years. Contact Form 7 backfill requires the Flamingo plugin for stored submissions.

= What data is sent to Burrow? =

Only the data you explicitly configure. Form fields are only included if you map them in the contract editor. WooCommerce events include order metadata but never customer PII — only an opaque customer token. See the Third-Party Service section for full details.

= Does the plugin work if Burrow is temporarily unavailable? =

Yes. Events are queued locally in a durable outbox table and retried automatically with exponential backoff until delivery succeeds.

== Screenshots ==

1. Onboarding wizard — guided setup with project selection and integration detection.
2. Form contract editor — choose tracking mode and map fields per form.
3. WooCommerce integration — automatic ecommerce event tracking.
4. Operations dashboard — view active contracts and connection status.
5. Outbox — monitor queued, sent, and failed events with payload inspection.

== Changelog ==

= 1.2.0 =
* SureForms: full forms integration — realtime submission tracking via `srfm_form_submit`, wizard setup with count-only and custom-field mapping, and 120-day submission stats.
* SureCart: ecommerce integration — `order.placed` and `item.purchased` at checkout confirmation, cancellations and refunds via SureCart webhooks, minor-unit currency conversion, and opaque customer identity.
* Wizard: SureForms and SureCart appear in integration detection, setup steps, Settings tabs, and the review checklist.
* Wizard: sidebar step preview and review readiness now include WPForms and Formidable Forms.

= 1.1.1 =
* Admin: fix fatal error `Call to undefined method Burrow_Admin::render_woocommerce_step()` — restore the WooCommerce setup step renderer removed in the 1.1.0 refactor while its wizard and Settings call sites remained.

= 1.1.0 =
* Connect: default Burrow base URL is now `https://app.useburrow.com`; support `BURROW_BASE_URL` env override.
* SDK: bump bundled `useburrow/sdk-php` to 0.9.9 (reserved canonical key / `feed_` prefix handling).
* Forms: complete WPForms and Formidable Forms wizard steps; selective form picker with 120-day volume sorting and gated save (Craft UX parity).
* Backfill: default historical window is Two years.
* Outbox: bulk retry all failed events from Dashboard and Outbox.
* System: report WordPress core `updateAvailable` / `latestVersion` in stack snapshots.
* Admin: post-onboarding **Settings** page (Overview, Integrations, providers, Connection) with automatic contract sync on save.

= 1.0.2 =
* Forms: fix Fluent Forms and Ninja Forms submission ingestion (correct hook, payload normalization, and form_id resolution).
* WooCommerce: emit `order.placed` only for revenue-countable statuses (processing, completed); include `orderStatus` on payloads and align backfill to the same filter.
* System: enrich heartbeat pings with CMS, PHP, plugin version, and plugin inventory summary tags.
* Admin: validate API keys during setup, preserve Gravity Forms wizard state, route Reconfigure into the wizard, improve custom-field mapping UI, and guard backfill queue actions with clearer progress labels.

= 1.0.1 =
* WooCommerce order backfill: only includes processing, completed, and on-hold orders (paid pipeline), not pending/cancelled drafts.
* Backfill worker: marks the job complete only when every source cursor is finished; empty batches mid-job no longer close the job early.
* Admin: queued backfill runs on WP-Cron only (avoids long synchronous admin requests on large stores).
* Aligns with Burrow PHP SDK 0.9.6+ for funnel events and `ecommerce.order.placed` shipping fields on event properties when using bundled SDK builds.

= 1.0.0 =
* Initial release.
* Guided onboarding wizard with project linking.
* Support for Gravity Forms, WPForms, Contact Form 7, Ninja Forms, Fluent Forms, and Formidable Forms.
* WooCommerce order lifecycle tracking (placed, fulfilled, refunded, cancelled).
* Durable outbox with exponential backoff retries.
* Historical data backfill.
* System stack snapshots and heartbeat monitoring.
* Provider-prefixed form IDs for cross-plugin uniqueness.

== Upgrade Notice ==

= 1.1.1 =
Fixes a fatal error on the WooCommerce setup step and Settings WooCommerce section introduced in 1.1.0. Upgrade immediately if you are on 1.1.0.

= 1.1.0 =
Craft feature parity: app.useburrow.com default, selective form picker, Settings auto-sync, bulk outbox retry, and SDK 0.9.9.

= 1.0.2 =
Fixes Fluent/Ninja Forms ingestion, tightens WooCommerce order.placed gating, and improves onboarding and backfill admin UX.

= 1.0.1 =
Improves backfill correctness and resilience; update the Composer-vendored PHP SDK to 0.9.6+ when building from source (`composer update`).

= 1.0.0 =
Initial release.
