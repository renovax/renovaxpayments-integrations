# RENOVAX Payments — Magento 2 Module

Native module so any **Magento 2 / Adobe Commerce** store can charge through
**RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). When
a payment confirms, RENOVAX sends a signed webhook and the order is invoiced
automatically.

---

## 1. Files included

| File | Purpose |
| --- | --- |
| `registration.php` + `composer.json` | Registers the `Renovax_Payments` module |
| `etc/module.xml` | Declaration + dependencies |
| `etc/config.xml` | Payment method defaults |
| `etc/payment.xml` | Payment method registration |
| `etc/adminhtml/system.xml` | Admin configuration screen |
| `etc/frontend/routes.xml` + `di.xml` | Frontend routes + ConfigProvider |
| `etc/db_schema.xml` | `renovax_invoice_id` column on `sales_order` |
| `Model/Payment.php` | `AbstractMethod` implementation (with refunds) |
| `Model/Api/Client.php` | Merchant API HTTP client |
| `Model/Ui/ConfigProvider.php` | Exposes config to checkout JS |
| `Controller/Redirect/Index.php` | Creates invoice + redirects to `pay_url` |
| `Controller/Webhook/Index.php` | Signed webhook receiver |
| `view/frontend/...` | Method UI component on the checkout |
| `i18n/*.csv` | Translations: en, es_ES, fr_FR, pt_BR, ru_RU, ar |

No external Composer dependencies. Compatible with Magento 2.4.x / Adobe Commerce.

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| Magento | 2.4.x / Adobe Commerce 2.4.x |
| PHP | 7.4+ (8.1+ recommended) |
| HTTPS | Mandatory — RENOVAX only delivers webhooks to `https://` URLs |
| Outbound HTTPS | TCP 443 open to `payments.renovax.net` |
| RENOVAX account | Active merchant at [payments.renovax.net](https://payments.renovax.net) |

---

## 3. Installation

### Step 1 — Get credentials

In **Merchants → (your merchant) → Edit → API Tokens** on RENOVAX Payments:

1. Create a **Bearer Token** (shown once).
2. Copy the **Webhook Secret**.

### Step 2 — Copy the module

Copy the `Renovax/Payments/` folder into your Magento `app/code/`:

```bash
cp -r Renovax/ <magento_root>/app/code/
```

### Step 3 — Enable and compile

From the Magento root:

```bash
bin/magento module:enable Renovax_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Step 4 — Configure in the admin

In **Stores → Configuration → Sales → Payment Methods → RENOVAX Payments**:

| Field | Value |
| --- | --- |
| **Enabled** | Yes |
| **Title** | `RENOVAX Payments` |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | from Step 1 (no spaces) |
| **Webhook Secret** | from Step 1 |
| **Invoice TTL (min)** | `15` |

### Step 5 — Register the webhook URL

The configuration screen shows the URL:

```text
https://YOUR-STORE.com/renovax/webhook
```

Paste it as `webhook_url` in the merchant settings on RENOVAX. Done.

---

## 4. Payment flow

1. Customer picks **RENOVAX Payments** at checkout.
2. Magento creates the order with status `pending_payment` and redirects
   to `/renovax/redirect`.
3. The controller calls `POST {api}/api/v1/merchant/invoices` sending the
   `increment_id` as `client_remote_id` (idempotent).
4. Customer is redirected to `pay_url` and chooses Crypto / Stripe / PayPal
   on the RENOVAX hosted checkout.
5. Once confirmed, RENOVAX POSTs a signed webhook to `/renovax/webhook`.
6. The module verifies HMAC, deduplicates by `X-Renovax-Event-Id`
   (Magento cache) and updates the order.

| RENOVAX `event_type` | Magento action |
| --- | --- |
| `invoice.paid` | Creates `Order\Invoice` (CAPTURE_OFFLINE) + state `processing` |
| `invoice.overpaid` | Same + flagged note |
| `invoice.partial` | Moves to `holded` with note |
| `invoice.expired` | `cancel()` (if not already invoiced) |

Refunds: from **Sales → Orders → View → Credit Memo** (full or partial).

---

## 5. Webhook filters (firewall / WAF)

`/renovax/webhook` receives HMAC-SHA256 signed webhooks. If your WAF or
firewall modifies the request, **every signature will fail with `401
invalid_signature`**.

### 5.1 Allow RENOVAX IPs

Request the current egress IP list at
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /renovax/webhook`.

### 5.2 Headers that must pass through unmodified

| Header | Purpose |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 of the raw body |
| `X-Renovax-Event-Type` | Event type |
| `X-Renovax-Event-Id` | Unique UUID (idempotency) |
| `Content-Type` | `application/json` |

### 5.3 WAF rules to disable **only** for this URL

| Rule | Action |
| --- | --- |
| Body buffering / rewriting | **Disable** |
| JSON validation / normalization | **Disable** |
| Anti-bot / CAPTCHA | **Exclude** |
| Rate limiting | **Whitelist** RENOVAX |
| Geo-blocking | **Use IPs**, not countries |

### 5.4 Nginx example (standard Magento config)

```nginx
location ^~ /renovax/webhook {
    client_max_body_size 1m;
    proxy_request_buffering off;
    try_files $uri $uri/ /index.php?$args;
    # Do not rewrite or strip X-Renovax-* headers
}
```

### 5.5 CSRF

The webhook implements `CsrfAwareActionInterface` and returns
`validateForCsrf() => true` (skipping CSRF since it validates HMAC).
**Do not** protect it with form keys or reCAPTCHA.

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` in logs | WAF mutates the body or `Webhook Secret` does not match |
| Method does not appear at checkout | Missing `setup:di:compile` + `cache:flush` after copying |
| `RENOVAX authentication failed` at checkout | Wrong or expired `Bearer Token` |
| Order is not invoiced after paying | Admin user permissions for invoices or missing `setup:upgrade` |
| `column renovax_invoice_id not found` | `setup:upgrade` was not run after copying the module |

Enable **Debug log** in the configuration to trace events in
`var/log/system.log`.

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
