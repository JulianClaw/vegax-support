# Security policy

## Reporting a vulnerability

Do **not** open a public issue for a security problem. Email **support@vegaxclaw.com** with:

- the plugin version (Plugins screen) and WordPress / WooCommerce versions,
- what an attacker can do, and how you reproduced it,
- whether a store connection or customer data is involved.

You get an acknowledgement within two business days. Please give us a reasonable window to ship a
fix before publishing details.

## Scope

This repository is the WooCommerce plugin. It handles:

- the store token and signing secret (stored in your own database, never sent to a third party),
- HMAC-signed requests between the store and the Vegax service,
- a signed `/order-lookup` route that returns one customer's order status to the service,
- an opt-in identity token that binds a chat session to a signed-in customer.

In scope: signature verification, authorisation on every REST route, escaping and output, nonce and
capability checks in the admin screens, data sent to the service. Out of scope: the hosted Vegax
service itself, and vulnerabilities that require an already-compromised administrator account.

## What we already enforce

- Every REST route declares its access rule in `permission_callback`.
- `/health` requires `manage_options`.
- Stored credentials are never printed back into the settings HTML.
- Data sharing stops in both directions when the consent box is unticked.
