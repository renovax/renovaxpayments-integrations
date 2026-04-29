# RENOVAX Payments — DHRU Fusion Gateway

Drop-in integration so any **DHRU Fusion** panel can charge through
**RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.).
When a payment confirms, RENOVAX sends a signed webhook and the invoice
is credited automatically.

---

## 1. Files included

| File | Copy to (on your DHRU server) |
| --- | --- |
| `modules/gateways/renovaxpayments.php` | `/modules/gateways/renovaxpayments.php` |
| `renovaxpaymentscallback.php` | `/renovaxpaymentscallback.php` (public root) |

Only **2 files**. No database changes required.

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| DHRU Fusion | Any version with access to `modules/gateways/` |
| PHP | 7.4+ with `curl` and `hash` extensions |
| HTTPS | Mandatory — RENOVAX only delivers webhooks to `https://` URLs |
| Outbound HTTPS | TCP 443 open to `payments.renovax.net` |
| RENOVAX account | Active merchant at [payments.renovax.net](https://payments.renovax.net) with at least one configured payment method |

---

## 3. Installation

### Step 1 — Get credentials

In **Merchants → (your merchant) → Edit → API Tokens** on RENOVAX Payments:

1. Create a **Bearer Token** (shown once — copy it).
2. Copy the **Webhook Secret**.

### Step 2 — Register the webhook URL

In the merchant settings on RENOVAX, set:

```text
https://YOUR-DHRU-DOMAIN.com/renovaxpaymentscallback.php
```

### Step 3 — Upload the files

```bash
chmod 644 modules/gateways/renovaxpayments.php
chmod 644 renovaxpaymentscallback.php
```

### Step 4 — Activate the gateway in DHRU

In the DHRU admin: **Configuration → Payment Gateways → RENOVAX Payments → Edit**.

| Field | Value |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | from Step 1 (no spaces) |
| **Webhook Secret** | from Step 1 |
| **Invoice TTL (min)** | `15` |
| **Status** | **Active** |

Done.

---

## 4. Callback filters (firewall / WAF)

`renovaxpaymentscallback.php` receives HMAC-SHA256 signed webhooks. If your
WAF, reverse proxy or firewall modifies the request, **every signature will
fail with `401 invalid_signature`**.

### 4.1 Allow RENOVAX Payments IPs

Request the current egress IP list from
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /renovaxpaymentscallback.php`.

### 4.2 Headers that must pass through unmodified

| Header | Purpose |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 of the raw body |
| `X-Renovax-Event-Type` | Event type (e.g. `invoice.paid`) |
| `X-Renovax-Event-Id` | Unique UUID per delivery |
| `Content-Type` | Must arrive as `application/json` |

### 4.3 WAF rules to disable **only** for this URL

| Rule | Action |
| --- | --- |
| Body buffering / rewriting | **Disable** — HMAC is computed over exact bytes |
| JSON validation / normalization | **Disable** |
| Anti-bot / CAPTCHA / JS challenge | **Exclude** this endpoint |
| Rate limiting | **Whitelist** RENOVAX IPs |
| Geo-blocking | **Use IPs**, not countries (RENOVAX may egress from multiple regions) |
| Body size limit | 1 MB is plenty (webhooks are < 4 KB) |

### 4.4 Configuration examples

**Cloudflare** — create a Page Rule or Configuration Rule for
`yourdomain.com/renovaxpaymentscallback.php`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx** — preserve headers and disable buffering:

```nginx
location = /renovaxpaymentscallback.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    # Do not modify or strip X-Renovax-* headers
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/renovaxpaymentscallback.php;
}
```

**Apache (.htaccess)** — do not apply `mod_security` or any body-rewriting
filter on this file:

```apache
<Files "renovaxpaymentscallback.php">
    SecRuleEngine Off
    Header unset X-Frame-Options
</Files>
```

---

## 5. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
