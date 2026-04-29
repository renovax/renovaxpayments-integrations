=== RENOVAX Payments for WooCommerce ===
Contributors: renovax
Tags: woocommerce, payment gateway, crypto, stripe, paypal, usdt, usdc, bitcoin
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-platform payment gateway for WooCommerce: Crypto (USDT, USDC, EURC, DAI on multiple chains), Stripe (cards), PayPal and more — single hosted checkout.

== Description ==

RENOVAX Payments lets your WooCommerce store accept payments through a single hosted checkout that supports:

* **Crypto** — USDT, USDC, EURC, DAI, PYUSD, FDUSD on BSC, Ethereum, Polygon, Arbitrum, Base, Optimism, Avalanche, Tron, Solana and more (automatic on-chain detection).
* **Stripe** — credit & debit cards.
* **PayPal**.
* More methods as RENOVAX adds them.

The customer chooses their preferred method on the RENOVAX-hosted checkout and the WooCommerce order is updated automatically once payment is confirmed via signed webhook (HMAC-SHA256).

= Features =

* One-click hosted checkout — no PCI scope on your server.
* Automatic order status updates: `paid`, `overpaid`, `partial`, `expired`.
* Signed webhooks (HMAC-SHA256) with idempotency.
* Refunds from the WooCommerce admin.
* High-Performance Order Storage (HPOS) compatible.
* Available in English, Spanish, French, Portuguese (BR), Russian and Arabic.

= Requirements =

* WooCommerce 8.0+
* A RENOVAX merchant account at https://payments.renovax.net
* PHP 7.4+

== Installation ==

1. Upload the `renovaxpayments` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload**.
2. Activate the plugin in **Plugins**.
3. Go to **WooCommerce → Settings → Payments → RENOVAX Payments**.
4. Paste your **Bearer Token** and **Webhook Secret** from the RENOVAX merchant dashboard.
5. Copy the **Webhook URL** shown on the settings screen and paste it into your merchant's `webhook_url` in RENOVAX.
6. Enable the gateway and save.

== Frequently Asked Questions ==

= How do I get a Bearer Token? =
In your RENOVAX merchant dashboard: **Merchants → Edit → API Tokens → Create**. The token is shown only once — copy it immediately.

= What happens if a customer overpays? =
The order is marked as paid and a note is added with the overpaid amount for your records.

= What happens if a customer underpays? =
The order is set to `on-hold` with a note flagging it for manual review.

= Are refunds supported? =
Yes. Refunds can be issued from the WooCommerce admin (full or partial); they call the RENOVAX refund API.

== Changelog ==

= 1.0.0 =
* Initial release.
* Hosted checkout with Crypto, Stripe and PayPal.
* Signed webhook receiver with idempotency.
* Refund support.
* i18n: en, es, fr, pt_BR, ru, ar.
