# RENOVAX Payments — WooCommerce Plugin

Drop-in plugin so any **WooCommerce** store can charge through
**RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.).
When a payment confirms, RENOVAX sends a signed webhook and the order
is marked as paid automatically.

---

## 1. Files included

| File | Path inside the ZIP |
| --- | --- |
| `renovaxpayments.php` | Bootstrap (plugin header + hooks) |
| `includes/class-renovax-api-client.php` | Merchant API HTTP client |
| `includes/class-wc-gateway-renovax.php` | `WC_Payment_Gateway` implementation |
| `includes/class-renovax-webhook.php` | Signed webhook receiver |
| `assets/icon.png` | Method icon shown at checkout |
| `languages/*.po` + `*.pot` | Translations: en, es, fr, pt_BR, ru, ar |
| `readme.txt` | wp.org-format header |

No database changes. No Composer dependencies.

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| WordPress | 6.0+ |
| WooCommerce | 8.0+ (HPOS — High-Performance Order Storage compatible) |
| PHP | 7.4+ with `hash` and the standard WP HTTP API |
| HTTPS | Mandatory — RENOVAX only delivers webhooks to `https://` URLs |
| Outbound HTTPS | TCP 443 open to `payments.renovax.net` |
| RENOVAX account | Active merchant at [payments.renovax.net](https://payments.renovax.net) with at least one configured payment method |

---

## 3. Installation

### Step 1 — Get credentials

In **Merchants → (your merchant) → Edit → API Tokens** on RENOVAX Payments:

1. Create a **Bearer Token** (shown once — copy it).
2. Copy the **Webhook Secret**.

### Step 2 — Compile translations (.po → .mo)

WordPress only loads `.mo` at runtime. From the `languages/` folder:

```bash
for f in renovaxpayments-*.po; do msgfmt "$f" -o "${f%.po}.mo"; done
```

Or use **Loco Translate** / **Poedit** from the WP admin.

### Step 3 — Package and upload

Zip the `renovaxpayments/` folder and upload it via
**Plugins → Add New → Upload Plugin**, or copy the folder into
`wp-content/plugins/`.

Activate the plugin in **Plugins**.

### Step 4 — Configure the gateway

In **WooCommerce → Settings → Payments → RENOVAX Payments**:

| Field | Value |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | from Step 1 (no spaces) |
| **Webhook Secret** | from Step 1 |
| **Invoice TTL (min)** | `15` |
| **Enabled** | ✓ |

### Step 5 — Register the webhook URL

The settings screen displays a **Webhook URL** like:

```text
https://YOUR-STORE.com/wp-json/renovax/v1/webhook
```

Copy it and paste it as `webhook_url` in the merchant settings on RENOVAX.
Done.

---

## 4. Payment flow

1. Customer chooses **RENOVAX Payments** at checkout.
2. The plugin calls `POST {api}/api/v1/merchant/invoices` sending the WC
   order ID as `client_remote_id` (idempotent — a retry returns the same
   invoice, no double-charge).
3. Customer is redirected to `pay_url` and chooses **Crypto / Stripe /
   PayPal** on the RENOVAX-hosted checkout.
4. Once confirmed, RENOVAX POSTs a signed webhook to
   `/wp-json/renovax/v1/webhook` with the header
   `X-Renovax-Signature: sha256=<hmac>`.
5. The plugin verifies the signature, deduplicates by
   `X-Renovax-Event-Id`, and updates the order.

| `event_type` | WooCommerce action |
| --- | --- |
| `invoice.paid` | `payment_complete()` + note with gross/net/fee |
| `invoice.overpaid` | `payment_complete()` + flagged overpaid note |
| `invoice.partial` | `on-hold` + manual review note |
| `invoice.expired` | `cancelled` (only if not already paid) |

Refunds: from the WC admin, calls
`POST /api/v1/merchant/invoices/{id}/refund` (full or partial).

---

## 5. Webhook filters (firewall / WAF)

The endpoint `/wp-json/renovax/v1/webhook` receives HMAC-SHA256 signed
webhooks. If your WAF, reverse proxy or firewall modifies the request,
**every signature will fail with `401 invalid_signature`**.

### 5.1 Allow RENOVAX Payments IPs

Request the current egress IP list from
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /wp-json/renovax/v1/webhook`.

### 5.2 Headers that must pass through unmodified

| Header | Purpose |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 of the raw body |
| `X-Renovax-Event-Type` | Event type (e.g. `invoice.paid`) |
| `X-Renovax-Event-Id` | Unique UUID per delivery (idempotency) |
| `Content-Type` | Must arrive as `application/json` |

### 5.3 WAF rules to disable **only** for this URL

| Rule | Action |
| --- | --- |
| Body buffering / rewriting | **Disable** — HMAC is computed over exact bytes |
| JSON validation / normalization | **Disable** |
| Anti-bot / CAPTCHA / JS challenge | **Exclude** this endpoint |
| Rate limiting | **Whitelist** RENOVAX IPs |
| Geo-blocking | **Use IPs**, not countries (RENOVAX may egress from multiple regions) |
| Body size limit | 1 MB is plenty (webhooks are < 4 KB) |
| WP security plugins (Wordfence, iThemes, etc.) | Exclude the path `/wp-json/renovax/v1/webhook` from their firewall |

### 5.4 Configuration examples

**Cloudflare** — create a Configuration Rule for
`yourdomain.com/wp-json/renovax/v1/webhook`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx** — preserve headers and disable buffering:

```nginx
location = /wp-json/renovax/v1/webhook {
    client_max_body_size 1m;
    proxy_request_buffering off;
    # Do not modify or strip X-Renovax-* headers
    try_files $uri $uri/ /index.php?$args;
}
```

**Apache (.htaccess)** — do not apply `mod_security` or any body-rewriting
filter on this route:

```apache
<LocationMatch "^/wp-json/renovax/v1/webhook">
    SecRuleEngine Off
</LocationMatch>
```

**Wordfence** — under **Wordfence → Firewall → All Firewall Options →
Whitelisted URLs**, add `wp-json/renovax/v1/webhook`.

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` in logs | WAF is modifying the body, or the `Webhook Secret` does not match |
| Order never moves to `processing` | The webhook URL isn't registered on RENOVAX, or the endpoint isn't public |
| `RENOVAX Payments authentication failed` at checkout | Wrong or expired `Bearer Token` |
| Customer sees `RENOVAX returned an incomplete response` | The merchant has no active payment methods on RENOVAX |

Enable **Debug log** in the plugin settings to see the full trace under
**WooCommerce → Status → Logs** with tag `renovax-*`.

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
