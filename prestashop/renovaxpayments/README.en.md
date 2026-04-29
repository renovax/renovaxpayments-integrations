# RENOVAX Payments — PrestaShop Module

Integration that lets your **PrestaShop** store accept payments through
**RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). When
the payment is confirmed, RENOVAX delivers a signed webhook and the order is
materialised (or moved to PAID) automatically.

Compatible with **PrestaShop 1.7.x, 8.x and 9.x** (PHP 7.2 → 8.3).

---

## 1. Files included

```
renovaxpayments/
├── renovaxpayments.php
├── config.xml
├── logo.png
├── classes/
│   ├── RenovaxApiClient.php
│   └── RenovaxLogger.php
├── controllers/front/
│   ├── validation.php
│   ├── webhook.php
│   ├── return.php
│   └── cancel.php
├── sql/
│   ├── install.php
│   └── uninstall.php
├── translations/
│   ├── en.xlf  es.xlf  fr.xlf  pt.xlf  ru.xlf  ar.xlf
└── views/templates/
    ├── front/return.tpl
    └── hook/payment_return.tpl
```

Drop the `renovaxpayments/` folder into your shop's `modules/` directory. The
installer creates the `ps_renovax_events` table (used for webhook
idempotency).

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| PrestaShop | 1.7.x, 8.x or 9.x |
| PHP | 7.2+ with `curl`, `hash`, `json` extensions |
| HTTPS | Mandatory — RENOVAX only delivers webhooks to `https://` URLs |
| Outbound HTTPS | TCP 443 open to `payments.renovax.net` |
| MySQL/MariaDB | InnoDB (required for `INSERT IGNORE` on `event_id`) |
| RENOVAX account | Active merchant on [payments.renovax.net](https://payments.renovax.net) with at least one payment method enabled |

---

## 3. Installation

### Step 1 — Get credentials

In **Merchants → (your merchant) → Edit → API Tokens** in RENOVAX Payments:

1. Create a **Bearer Token** (shown only once — copy it).
2. Copy the **Webhook Secret**.

### Step 2 — Upload the module

Copy the `renovaxpayments/` folder into your shop's `modules/` directory (FTP,
SSH or back-office ZIP upload).

```bash
chmod -R 644 modules/renovaxpayments
find modules/renovaxpayments -type d -exec chmod 755 {} \;
```

### Step 3 — Install from the back office

**Modules → Module Manager → search "RENOVAX" → Install.**

Once installed, click **Configure** and paste:

| Field | Value |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | from Step 1 (no spaces) |
| **Webhook Secret** | from Step 1 |
| **Invoice TTL (min)** | `15` |
| **Debug log** | `Off` (turn on only to debug) |

The configure screen shows a **Webhook URL** field of the form:

```text
https://YOUR-SHOP/index.php?fc=module&module=renovaxpayments&controller=webhook
```

### Step 4 — Register the webhook URL

In the merchant configuration on RENOVAX, paste that URL into the
`webhook_url` field. Done.

### Step 5 — Enable the payment method

**Payment → Preferences** (PS 1.7) or **Payment → Payment Methods** (PS 8/9):
make sure RENOVAX Payments is enabled for the desired currencies and customer
groups.

---

## 4. Webhook firewall / WAF rules

`controllers/front/webhook.php` receives HMAC-SHA256-signed webhooks. If your
WAF, reverse proxy or firewall mutates the request, **all signatures will
fail with `401 invalid_signature`**.

### 4.1 Allow RENOVAX egress IPs

Request the current egress IP list at
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /index.php?fc=module&module=renovaxpayments&controller=webhook`.

### 4.2 Headers that must pass through unmodified

| Header | Purpose |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 of the raw body |
| `X-Renovax-Event-Type` | Event type (e.g. `invoice.paid`) |
| `X-Renovax-Event-Id` | Unique UUID per delivery (idempotency) |
| `Content-Type` | Must arrive as `application/json` |

### 4.3 WAF rules to disable **only** for this URL

| Rule | Action |
| --- | --- |
| Body buffering / rewrite | **Disable** — HMAC is computed on exact bytes |
| JSON validation / normalization | **Disable** |
| Anti-bot / CAPTCHA / JS challenge | **Exclude** this endpoint |
| Rate limiting | **Whitelist** RENOVAX IPs |
| Geo-blocking | **Use IPs**, not countries |
| Body size cap | 1 MB is enough |

### 4.4 Example configuration

**Cloudflare** — Configuration Rule for the webhook URL:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx** — preserve headers and disable body buffering:

```nginx
location ~ ^/index\.php$ {
    if ($arg_module = "renovaxpayments") {
        client_max_body_size 1m;
        proxy_request_buffering off;
    }
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
}
```

**Apache (.htaccess)** — disable mod_security on the RENOVAX webhook:

```apache
<If "%{QUERY_STRING} =~ /module=renovaxpayments&controller=webhook/">
    SecRuleEngine Off
</If>
```

---

## 5. How it works

```text
Customer ── checkout ──▶ validation.php ──▶ POST /api/v1/merchant/invoices ──▶ RENOVAX
                                                       │
                                                  pay_url
                                                       ▼
                                                Hosted checkout
                                                (crypto/Stripe/PayPal)
                                                       │
                                                  webhook
                                                       ▼
RENOVAX ──▶ POST /…&controller=webhook ──▶ HMAC OK ──▶ flush 200 ──▶ DB
                                                                       │
                                                                 validateOrder
                                                                  → PS_OS_PAYMENT
```

- The PrestaShop **order is only created when `invoice.paid` /
  `invoice.overpaid` arrives**. This avoids orphan unpaid orders.
- The RENOVAX `invoice id` is saved in `OrderPayment.transaction_id`, so you
  can locate the payment from the back office.
- Idempotency is enforced by `X-Renovax-Event-Id` against
  `ps_renovax_events`. Replays return 200 without side effects.
- The `flush_and_close` pattern flushes the 200 response **before** any DB
  writes, so a remote disconnect cannot abort order creation.

### State mapping

| RENOVAX event | PrestaShop action |
| --- | --- |
| `invoice.paid` | create order with `PS_OS_PAYMENT` (or transition if already exists) |
| `invoice.overpaid` | same + private "OVERPAID gross/net/fee" note |
| `invoice.partial` | move to `RENOVAX — Pago parcial` state (created at install) |
| `invoice.expired` | if order exists and not paid → `PS_OS_CANCELED` |

---

## 6. Refunds

When you create a **Standard Refund** or **Partial Refund** from the back
office, the module calls `POST /api/v1/merchant/invoices/{id}/refund` with the
slip amount and the *cancelNote* as reason.

The result is recorded as a private order note. If RENOVAX rejects the
refund (e.g. invoice not refundable), the note reflects that and you must
resolve it from the RENOVAX dashboard.

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `401 invalid_signature` in logs | WAF rewrote the body, or the secret was mistyped | Follow section 4. Rotate the secret if in doubt. |
| `webhook_secret_not_configured` | Secret missing in module config | Paste it in *Configure*. |
| Payment completes but order doesn't appear | Webhook didn't arrive (firewall) or `ps_cart_id` was lost | Check the `[renovax]` log; ping support for connectivity. |
| `invoice mismatch order=… stored=… incoming=…` | Stale/duplicate webhook for a different invoice | Safe to ignore if the order is already paid; rejected by design. |
| `cart load failed: id=…` | Cart was deleted between invoice creation and webhook | Customer should retry; the invoice will TTL out. |
| Module doesn't appear at checkout | Bearer Token or Webhook Secret empty | Configure them — the module hides itself if missing. |
| `Settings updated.` but still broken | PrestaShop cache | Back office → *Advanced Parameters → Performance → Clear cache*. |

Enable verbose logging via **Configure → Debug log: Enabled**. Events appear
in *Advanced Parameters → Logs* filtering by `RenovaxPayments`.

---

## 8. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
