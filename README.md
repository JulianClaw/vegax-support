# Vegax Support — AI customer support for WooCommerce

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/vegax-support?label=wordpress.org)](https://wordpress.org/plugins/vegax-support/)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b)](https://wordpress.org/plugins/vegax-support/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://wordpress.org/plugins/vegax-support/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)
[![CI](https://github.com/JulianClaw/vegax-support/actions/workflows/ci.yml/badge.svg)](https://github.com/JulianClaw/vegax-support/actions/workflows/ci.yml)

The **WooCommerce adapter** for [Vegax](https://vegaxclaw.com/woocommerce). It puts an AI support
employee on your storefront, feeds it your real catalogue and policies, and lets it answer
*“where is my order?”* with the customer's actual order status — without exporting your customer list
to anyone.

The AI itself runs on the hosted Vegax service. **This repository is the plugin: open source, GPL,
auditable.** It contains no API keys and no customer data.

- **Install from WordPress.org:** https://wordpress.org/plugins/vegax-support/
- **Shopify store?** The approved Shopify app is [Vegax Support Autopilot](https://apps.shopify.com/vegax-support-autopilot).
- **Product page:** https://vegaxclaw.com/woocommerce

## What the plugin does

| Capability | Detail |
|---|---|
| Chat widget | Injects `widget.js` into the storefront through `wp_footer` — no theme edits |
| Knowledge sync | `POST /api/woo/knowledge` — products and policy pages, signed, keeping your `brand_color` and `custom_rules` |
| Heartbeat | `POST /api/woo/heartbeat` — reports the store is alive, with WP / Woo / HPOS versions |
| Order lookup (WISMO) | The service calls `GET /wp-json/vegax/v1/order-lookup` with an HMAC signature; the AI answers with the real status, total and tracking number |
| Order events | `POST /api/woo/order-event` when an order changes status, so the assistant is never stale |

## Install

**From the WordPress plugin directory (recommended)**

1. Install and activate WooCommerce, and publish your product and policy pages.
2. In WP Admin → **Plugins → Add New**, search for **Vegax Support** → **Install** → **Activate**.
   (Or download the zip from https://wordpress.org/plugins/vegax-support/ and upload it.)
3. Go to **WooCommerce → Vegax Support**.
4. Click **Connect with Vegax**, approve the store on the Vegax site, and you are returned with the
   store connected. Stores that already hold a token and secret, or use a one-time pairing code,
   keep working exactly as before.
5. Optional: switch on **Identity verification** to bind chats to signed-in customers, then click
   **Test connection**.

`store_id()` is derived from the host of `home_url()`. Set `store_id_override` when the store sits
behind a CDN or proxy, or when WP constants override the site URL (staging, for example).

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- WooCommerce 7.0 or newer (HPOS compatible)
- A Vegax store connection (free trial, then a paid plan — pricing at https://vegaxclaw.com/#pricing)

## Security model

- **Signed in both directions.** Every request carries an HMAC-SHA256 signature over the raw body
  and a timestamp; the plugin rejects stale or unsigned calls.
- **No customer export.** The service asks the store for one customer's order at a time, through
  `order-lookup`, and receives only what the answer needs.
- **Secrets stay in your database**, in the plugin settings, never in this repository.
- **Identity verification (opt-in)** ties a chat session to a signed-in customer so an order status
  can never be answered to the wrong person.

```
Plugin → service
POST /api/woo/{heartbeat,knowledge,order-event}
X-Vegax-Token:     <store_token>
X-Vegax-Timestamp: <unix ts>
X-Vegax-Signature: hex(hmac_sha256(ts + "." + raw_body, store_secret))

Service → plugin
GET /wp-json/vegax/v1/order-lookup  (same signature scheme, replayed-protection on the timestamp)
```

## Development

```bash
composer install
composer run lint     # phpcs with phpcs.xml.dist
composer run test     # phpunit
php php_syntax_check.py
./build.sh            # builds the distributable zip into build/
```

`verify_submission.py` checks the built package against the WordPress.org submission rules before a
release. CI (`.github/workflows/ci.yml`) runs the PHP matrix, PHPUnit and PHPCS on every push;
`.github/workflows/release.yml` builds the package.

## Scope of this repository

This is the WooCommerce plugin. The hosted AI service — retrieval, the support model, billing and
tenant isolation — is proprietary and not part of this repository. Issues about the plugin's PHP
code, hooks, the REST endpoint or the build are welcome here.

## Contributing

Bug reports and pull-request fixes are welcome. Please include the WordPress and WooCommerce
versions you tested, and whether HPOS is enabled. Security issues: email support@vegaxclaw.com
instead of opening a public issue.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE). Vegax is a product of NCK Group LLC.

If this plugin is useful to you, a star helps other store owners find it.
