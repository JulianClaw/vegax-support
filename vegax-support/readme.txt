=== Vegax Support ===
Contributors: vegaxclaw
Tags: woocommerce, customer support, live chat, order tracking, chatbot
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0
WC tested up to: 11.1

WooCommerce customer support: answers from your own catalogue and lets customers check their own order status in chat.

== Description ==

[Vegax Support](https://vegaxclaw.com/woocommerce) puts a support agent on your WooCommerce store. It reads your product catalogue and policy pages, so it answers from what you actually sell and how you actually do business &mdash; never on guesses.

* Answers product, price, stock, shipping and returns questions.
* Lets a customer check their own order status right in the chat (WISMO).
* Replies in the language the customer writes in.
* Chat bubble appears on your storefront without editing your theme.
* Works with WooCommerce High-Performance Order Storage and the legacy post tables.

The [Vegax service](https://vegaxclaw.com) is required. The plugin itself is open-source (GPL) and is never locked or broken: you see the settings screen, the connect screen, the notices and the uninstall flow with or without a subscription. Only the answering engine lives outside your site.

A 7-day free trial is included. After the trial you can choose a plan to keep the service running. See [vegaxclaw.com/woocommerce](https://vegaxclaw.com/woocommerce) for current plans and pricing.

== External services ==

This plugin relies on the Vegax service, a third-party hosted service, to generate support answers and order-status replies.

* **Service:** Vegax &mdash; [https://vegaxclaw.com](https://vegaxclaw.com)
* **Base endpoint:** https://office.vegaxclaw.com
* **Service terms of use:** [https://vegaxclaw.com/terms](https://vegaxclaw.com/terms)
* **Privacy policy:** [https://vegaxclaw.com/privacy](https://vegaxclaw.com/privacy)

**What the plugin sends and when**

Once the administrator has entered the store token and secret (or connected with the **Connect with Vegax** button, or used a one-time pairing code) **and ticked the data-sharing consent checkbox** on the settings screen, the plugin sends the following data to the Vegax service.

* **Catalogue sync** (separate from the heartbeat, triggered by an administrator or by the daily sync cron &mdash; never on heartbeat): product id, name, SKU, type, prices (regular, sale and current), on-sale flag, stock status, in-stock flag, stock quantity, categories, product link, image link, short description, attributes, weight, dimensions, variant data (options, price, stock), plus a site metadata block (site name, site URL, store key, WooCommerce version, WordPress version, PHP version, currency, HPOS flag, product count).
* **Policy pages and custom rules** (shipping, returns, warranty and similar&ndash; named pages found on your own site), plus the FAQs and store rules the administrator types into the settings, as plain text: sent on the same sync.
* **Order status events** (order number, status, status label, order date, total, currency, item count, shipped flag, shipment tracking providers and URLs) and a hashed customer e-mail: sent when an order status changes. The clear-text e-mail is only sent if the administrator enables the "Send the customer e-mail with order events" option.
* **Daily heartbeat** (a small status payload sent once a day): store key, site URL, site name, WordPress version, WooCommerce version, PHP version, currency, HPOS flag, order-lookup enabled flag, widget enabled flag, product count.
* **Token refresh** (only for stores connected with the expiring-token connection, and only when the access token is close to expiry): the store key and the refresh token, so the service can issue a fresh token pair. This runs in the background and is never triggered by a shopper's page view.
* **Pairing request** (only when the administrator uses the one-time pairing code or the Connect with Vegax authorization): the site URL, the one-time code and the plugin version.
* **Identity token** (only when the administrator switches on Identity verification, and only for a signed-in customer): a short-lived signed token carrying the customer's store id and an expiry. It is a signature, not personal data — no name, e-mail or address is included.

The storefront chat widget script is loaded from the Vegax service so replies can be generated. It is only loaded when (a) the widget is enabled in the settings, and (b) the store is connected to the service.

**What is never sent**

Customer names, addresses, phone numbers, order addresses and payment details are never transmitted. Your WordPress or WooCommerce credentials are never transmitted. No order data leaves your store in bulk &mdash; only per-order responses to a customer who provides their own e-mail and order number.

== Installation ==

1. Install WooCommerce and set up your products and policy pages.
2. Install the &ldquo;Vegax Support&rdquo; plugin from the WordPress Plugin Directory.
3. Go to **WooCommerce &gt; Vegax Support** in your WordPress admin.
4. Click **Connect with Vegax** on the settings screen and approve the store on the Vegax site — you are returned and the store connects automatically. (Stores that already hold a token and secret, or use a one-time pairing code, keep working exactly as before.)
5. (Optional) Switch on **Identity verification** to tie chats to signed-in customers, then click **Test connection** to verify.

== Frequently Asked Questions ==

= Does this replace my staff? =

It answers common questions and order-status lookups. It does not handle escalated support, exchanges, refunds or outbound calls &mdash; those still go to your team.

= Can customers see my prices and stock? =

Yes, through the widget. That is the point &mdash; so customers get immediate answers.

= Where can I see the current plans and pricing? =

At [vegaxclaw.com/woocommerce](https://vegaxclaw.com/woocommerce).

= Is the plugin open source? =

Yes, it is GPL-2.0-or-later. The Vegax service is a paid subscription; the plugin itself is never locked or broken when the subscription expires.

== Changelog ==

= 1.0.9 =

* Security: the `/health` REST route is now restricted to WordPress administrators (`manage_options`). It reports plugin, WooCommerce, WordPress and PHP versions plus connection and synchronisation state, so it must not be readable by an anonymous visitor. Nothing in the Vegax service called that route, so no functionality changed.
* The signature check for the `/order-lookup` and `/knowledge` routes now runs in each route's `permission_callback` (the documented place for authorisation) instead of only inside the callback body, so every route declares its access rule explicitly.


= 1.0.8 =

* One-click connect: the **Connect with Vegax** button sends the merchant to a Vegax authorization page that names the store, then returns and connects the store automatically using a single-use, short-lived code together with an anti-forgery `state` value. The older paste-a-pairing-code path is kept for backward compatibility and keeps working unchanged.
* Added an optional **Identity verification** switch (off by default). When on, a signed-in customer is tagged with a short-lived signed token so a chat can never be answered for the wrong person (for example when a cache reuses a conversation).
* Data sharing is now strictly opt-in: the consent checkbox is unticked by default on a fresh install, and the plugin sends nothing to the Vegax service until the administrator ticks it. (1.0.7 pre-ticked the box for the previous default.)
* Unified the plugin name to a single identifier `vegax-support` everywhere: the plugin folder, the main file, the text domain, the readme and the `data-plugin` marker printed by the widget.
* Changed the outbound `User-Agent`/plugin header value to `Vegax-Support` for consistency.
* Updated the readme's External services and Description sections so the documented fields match the payload that is actually sent.
* The storefront widget tag is now guaranteed cache-safe: when Identity verification is on, a response that carries a signed customer token is marked uncacheable (DONOTCACHEPAGE plus no-cache headers plus `Vary: Cookie`), so a page cache or CDN can never hand one shopper's verified chat to another visitor.
* Consolidated all input sanitising and output escaping into one shared helper (`Vegax_AIS_Safe`) used by the admin screen and the storefront widget.
* Added developer extension points — see the plugin README "Developers" section: `vegax_ais_widget_tag_attributes`, `vegax_ais_knowledge_product`, `vegax_ais_heartbeat_payload`, `vegax_ais_order_event_payload`.
* Added a PHPUnit test suite and a GitHub Actions workflow (syntax check across PHP versions, PHPUnit, PHPCS) for maintainers.
* The connection now uses a **short-lived access token together with a longer-lived refresh token** (issued when the store is connected). The plugin reads the lifetime from the service response and renews the pair in the **background** (a WordPress cron job) before it expires &mdash; never during a shopper's page view, so the storefront is never slowed down. If a renewal cannot be completed the store **pauses sending data** instead of failing, and the settings screen shows a **Reconnect with Vegax** prompt. Stores connected with the older static token and secret keep working exactly as before.

= 1.0.7 =

* Complete rewrite of the WordPress.org listing readme: every data field now matches the actual payload and each outbound call is documented separately (catalogue sync, heartbeat, order event, pairing request).
* Added explicit data-sharing consent checkbox (WooCommerce &gt; Vegax Support). The checkbox is pre-ticked for stores that were already connected; on a fresh install it is unticked until the store connects.
* Added in-plugin disconnect action that clears all credentials and stops all outbound traffic.
* Added in-plugin privacy policy text (Tools &gt; Privacy).
* Widget script no longer loads before the store is connected &mdash; prevents any pre-consent phone-home.
* Brand text is &ldquo;Vegax Support&rdquo; with no engine name shown to shoppers.
* Plugin header requires at least WordPress 6.5 (for `Requires Plugins` support).
* Removed stale `Domain Path: /languages` header (directory did not exist).

= 1.0.6 =

* Removed the old default name from every user-facing screen. The canned agent name &ldquo;Vegax Support&rdquo; replaces the previous default.
* Brand colour defaults to `#07A87F` (`#18B287` was the old one).
* Added HPOS compatibility declaration.
* Added custom store rules and merchant FAQ fields to the settings screen.
* Widget is hidden on the checkout page by default.

= 1.0.0 =

* Initial release.

== Upgrade Notice ==

= 1.0.8 =

Data sharing is now opt-in. After updating, the plugin stops sending your catalogue and order status events until you tick "Data sharing" on the WooCommerce > Vegax Support settings screen. The storefront widget keeps working once the store is connected.

= 1.0.7 =

The widget script no longer loads before the store is connected. If you had the widget enabled but kept the token/secret fields empty, you will not see the widget until you connect.