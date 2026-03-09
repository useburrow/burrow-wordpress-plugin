=== Burrow ===
Contributors: useburrow
Tags: analytics, events, forms, ecommerce, tracking
Requires at least: 5.6
Tested up to: 6.5
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your WordPress site to Burrow with minimal setup. Auto-detects forms and commerce activity and sends contract-based events.

== Description ==

Burrow connects your WordPress site to the Burrow platform with minimal setup. It auto-detects forms and commerce activity, and sends contract-based events (system, forms, ecommerce) via a resilient queued sync so reporting stays accurate even during downtime.

**Features:**

* Minimal setup — just add your API key and you're ready to go
* Auto-detects popular form plugins (Contact Form 7, Gravity Forms, WPForms, and more)
* Integrates with WooCommerce for ecommerce event tracking
* Resilient queued sync keeps events safe during temporary downtime
* Contract-based event schema for consistent, reliable reporting

== Installation ==

1. Upload the `burrow` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings > Burrow** and enter your Burrow API key.
4. That's it — Burrow will start auto-detecting and syncing events automatically.

== Frequently Asked Questions ==

= Where do I get a Burrow API key? =

Sign up at [useburrow.com](https://useburrow.com) to get your API key.

= Which form plugins are supported? =

Burrow auto-detects submissions from Contact Form 7, Gravity Forms, WPForms, Ninja Forms, and other popular form plugins.

= Does Burrow work with WooCommerce? =

Yes. Burrow integrates with WooCommerce to track ecommerce events such as purchases, cart activity, and more.

== Screenshots ==

1. Settings page — enter your API key to connect.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
