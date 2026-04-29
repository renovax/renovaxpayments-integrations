# RENOVAX Payments — WHMCS Gateway

Native integration so any **WHMCS** install can charge through **RENOVAX
Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). When a payment
confirms, RENOVAX sends a signed webhook and the WHMCS invoice is credited
automatically via `addInvoicePayment()`.

---

## 1. Files included

| File | Copy to (on your WHMCS server) |
| --- | --- |
| `modules/gateways/renovaxpayments.php` | `/modules/gateways/renovaxpayments.php` |
| `modules/gateways/callback/renovaxpayments.php` | `/modules/gateways/callback/renovaxpayments.php` |
| `lang/overrides/english.php` | `/lang/overrides/english.php` (merge keys) |
| `lang/overrides/spanish.php` | `/lang/overrides/spanish.php` |
| `lang/overrides/french.php` | `/lang/overrides/french.php` |
| `lang/overrides/brazilian-portuguese.php` | `/lang/overrides/brazilian-portuguese.php` |
| `lang/overrides/russian.php` | `/lang/overrides/russian.php` |
| `lang/overrides/arabic.php` | `/lang/overrides/arabic.php` |

No database changes. No Composer dependencies.

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| WHMCS | 8.x+ (tested with 8.10) |
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

### Step 2 — Upload the files

Via FTP/SCP, copy the files preserving the structure:

```bash
chmod 644 modules/gateways/renovaxpayments.php
chmod 644 modules/gateways/callback/renovaxpayments.php
chmod 644 lang/overrides/*.php
```

### Step 3 — Activate the gateway in WHMCS

In the WHMCS admin: **Setup → Apps & Integrations → Payment Gateways →
All Payment Gateways → "RENOVAX Payments" → Activate**.

| Field | Value |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | from Step 1 (no spaces) |
| **Webhook Secret** | from Step 1 |
| **Invoice TTL (min)** | `15` |
| **Debug log** | Tick to debug (optional) |

### Step 4 — Register the webhook URL

In the merchant settings on RENOVAX, set:

```text
https://YOUR-WHMCS-DOMAIN.com/modules/gateways/callback/renovaxpayments.php
```

### Step 5 — Test

Create a test invoice in WHMCS, click **Pay Now** → it should redirect to
the RENOVAX checkout. After payment the invoice is set to `Paid`.

Done.

---

## 4. Payment flow

1. Customer opens the WHMCS invoice and clicks **Pay Now**.
2. WHMCS calls `renovaxpayments_link()` which creates a RENOVAX invoice
   (`POST /api/v1/merchant/invoices`) with `client_remote_id = invoiceid`.
3. Customer is redirected to `pay_url` and chooses **Crypto / Stripe /
   PayPal** on the RENOVAX hosted checkout.
4. Once confirmed, RENOVAX POSTs a signed webhook to
   `/modules/gateways/callback/renovaxpayments.php` with header
   `X-Renovax-Signature: sha256=<hmac>`.
5. The callback verifies the signature, deduplicates with `checkCbTransID()`
   and calls `addInvoicePayment()` to credit the WHMCS invoice.

| RENOVAX `event_type` | WHMCS action |
| --- | --- |
| `invoice.paid` | `addInvoicePayment(invoiceid, eventId, amount_received_fiat, fee, 'renovaxpayments')` |
| `invoice.overpaid` | Same + flagged note in the log |
| `invoice.partial` | `logTransaction` only (manual review) |
| `invoice.expired` | `logTransaction` only (WHMCS handles its own timeout) |

**Refunds**: from **Billing → Transactions → (transaction) → Refund**,
WHMCS calls `renovaxpayments_refund()` which calls
`POST /api/v1/merchant/invoices/{id}/refund` on RENOVAX.

---

## 5. Callback filters (firewall / WAF)

`/modules/gateways/callback/renovaxpayments.php` receives HMAC-SHA256 signed
webhooks. If your WAF, reverse proxy or firewall modifies the request,
**every signature will fail with `401 invalid_signature`**.

### 5.1 Allow RENOVAX Payments IPs

Request the current egress IP list at
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /modules/gateways/callback/renovaxpayments.php`.

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
| Geo-blocking | **Use IPs**, not countries |
| Body size limit | 1 MB is plenty (webhooks are < 4 KB) |

### 5.4 Configuration examples

**Cloudflare** — Configuration Rule for
`yourdomain.com/modules/gateways/callback/renovaxpayments.php`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**
- Cloudflare APO / Auto Minify: **Off** (must not touch the body)

**Nginx**:

```nginx
location = /modules/gateways/callback/renovaxpayments.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    # Do not modify or strip X-Renovax-* headers
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/modules/gateways/callback/renovaxpayments.php;
}
```

**Apache (.htaccess)**:

```apache
<Files "renovaxpayments.php">
    SecRuleEngine Off
</Files>
```

### 5.5 mod_security / ConfigServer Firewall (CSF/LFD)

Many cPanel servers run WHMCS behind **mod_security** and/or **CSF/LFD**.
Make sure to:
- Exclude `/modules/gateways/callback/renovaxpayments.php` from the OWASP
  CRS rule set.
- Whitelist RENOVAX IPs in CSF (`csf.allow`) to avoid hit-count blocks.

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` in logs | WAF mutates the body or `Webhook Secret` does not match |
| WHMCS invoice never moves to `Paid` | Webhook URL not registered in RENOVAX, or the callback is not public |
| `RENOVAX authentication failed` at checkout | Wrong or expired `Bearer Token` |
| Customer sees `RENOVAX returned an incomplete response` | Merchant has no active payment methods on RENOVAX |
| Refund declined | The stored `transid` is not a valid RENOVAX invoice UUID (verify `addInvoicePayment` received the correct `event_id`) |

Enable **Debug log** in the gateway settings. Events appear in
**Utilities → Logs → Gateway Log** filtered by `renovaxpayments`.

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
