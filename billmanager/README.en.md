# RENOVAX Payments — BILLmanager Module (ISPsystem)

Native module so any **BILLmanager 6** install can charge through **RENOVAX
Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). When a payment
confirms, RENOVAX sends a signed webhook and BILLmanager updates the payment
status to `paid` automatically.

> **Note about ISPmanager**: ISPmanager is the **hosting control panel**
> from ISPsystem (manages domains, sites, mail, etc.) — it **does not
> process payments**. The official billing system in the ISPsystem ecosystem
> is **BILLmanager**, which connects to one or more ISPmanager instances.
> That's why this integration targets BILLmanager (where end customers and
> invoices live).

---

## 1. Files included

| File | Copy to (on your BILLmanager server) |
| --- | --- |
| `processing/pmrenovax.php` | `/usr/local/mgr5/processing/pmrenovax.php` |
| `etc/billmgr_mod_pmrenovax.xml` | `/usr/local/mgr5/etc/xml/billmgr_mod_pmrenovax.xml` |
| `callback/renovax_callback.php` | Web-served, e.g. `/var/www/billmgr/callback/renovax_callback.php` |

> The manifest XML embeds all 6 languages inline (en, es, fr, pt, ru, ar).

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| BILLmanager | 6.x (tested with 6.108+) |
| PHP CLI | 7.4+ with `curl` and `hash` |
| Root access | Required to drop files in `/usr/local/mgr5/` |
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

As root, via SCP:

```bash
scp processing/pmrenovax.php          root@billmgr:/usr/local/mgr5/processing/
scp etc/billmgr_mod_pmrenovax.xml     root@billmgr:/usr/local/mgr5/etc/xml/
scp callback/renovax_callback.php     root@billmgr:/var/www/billmgr/callback/
ssh root@billmgr 'chmod 755 /usr/local/mgr5/processing/pmrenovax.php && chmod 644 /usr/local/mgr5/etc/xml/billmgr_mod_pmrenovax.xml'
```

### Step 3 — Configure callback environment

The HTTP callback needs the `webhook_secret`. Set it on the host or expose
it as a web server environment variable:

```bash
# Option A: edit /var/www/billmgr/callback/renovax_callback.php
define('RENOVAX_WEBHOOK_SECRET', 'secret-from-step-1');

# Option B: environment variable (recommended)
echo 'export RENOVAX_WEBHOOK_SECRET="secret-from-step-1"' >> /etc/apache2/envvars   # Apache
# or for nginx + php-fpm:
echo 'env[RENOVAX_WEBHOOK_SECRET] = "secret-from-step-1"' >> /etc/php/7.4/fpm/pool.d/www.conf
```

### Step 4 — Restart BILLmanager

```bash
/usr/local/mgr5/sbin/mgrctl -m billmgr mgrservice.restart
```

### Step 5 — Activate the payment method

In the BILLmanager admin: **Provider → Payment methods → Add**.

| Field | Value |
| --- | --- |
| **Payment module** | RENOVAX Payments |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | from Step 1 (no spaces) |
| **Webhook Secret** | from Step 1 |
| **Invoice TTL (min)** | `15` |

### Step 6 — Register the webhook URL

In the merchant settings on RENOVAX, set:

```text
https://YOUR-BILLMANAGER.com/callback/renovax_callback.php
```

Done.

---

## 4. Payment flow

1. Customer opens a BILLmanager invoice and picks **RENOVAX Payments**.
2. BILLmanager invokes `pmrenovax.php --command PreparePayment`.
3. The script creates a RENOVAX invoice (`POST /api/v1/merchant/invoices`)
   with `client_remote_id = billmgr_payment_id` and returns the `pay_url`.
4. BILLmanager redirects the customer to the `pay_url`.
5. Customer pays on the RENOVAX hosted checkout (Crypto/Stripe/PayPal).
6. RENOVAX POSTs a signed webhook to `/callback/renovax_callback.php`.
7. The callback verifies HMAC, deduplicates by `X-Renovax-Event-Id`, and
   forwards the event to `pmrenovax.php --command PayCallback`.
8. `PayCallback` runs `mgrctl payment.edit elid=… status=paid`.

| RENOVAX `event_type` | BILLmanager status |
| --- | --- |
| `invoice.paid` | `status=paid` |
| `invoice.overpaid` | `status=paid` (review in logs) |
| `invoice.partial` | `status=inpay` |
| `invoice.expired` | `status=cancelled` |

**Refunds**: the script declares `allow_partial_refund=true`. When the
admin issues a refund from BILLmanager, it will call the RENOVAX API in a
future version (v1.1 — see `Out of scope`).

---

## 5. Callback filters (firewall / WAF)

`/callback/renovax_callback.php` receives HMAC-SHA256 signed webhooks. If
your WAF, reverse proxy or firewall modifies the request, **every signature
will fail with `401 invalid_signature`**.

### 5.1 Allow RENOVAX Payments IPs

Request the current egress IP list at
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /callback/renovax_callback.php`.

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

### 5.4 Nginx example

```nginx
location = /callback/renovax_callback.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/callback/renovax_callback.php;
}
```

### 5.5 ISPsystem firewall (if active)

If you use the built-in ISPmanager/BILLmanager firewall or **CSF/LFD** on
the same host, make sure to whitelist RENOVAX IPs (`csf.allow`) and exclude
the callback URL from any anti-bot challenge.

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` in logs | WAF mutates the body or `RENOVAX_WEBHOOK_SECRET` does not match |
| `processing_script_missing` | Wrong path in `RENOVAX_PMRENOVAX_PATH` or wrong file permissions |
| Payment never moves to `paid` after paying | `mgrctl` is not executable by the web user, or `billmgr_payment_id` missing in metadata |
| `create_invoice_failed status=401` | Wrong or expired Bearer Token |
| `create_invoice_failed status=422` | Currency not supported by the merchant — use one of the merchant's defaults |
| `spawn_failed` in the callback | The web server user cannot invoke `php` CLI, or `proc_open` is disabled in `php.ini` |

Enable detailed logging:

```bash
tail -f /usr/local/mgr5/var/billmgr.log | grep renovax
tail -f /var/log/php_errors.log | grep renovax
```

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)

---

## Out of scope v1

- Automatic refunds via a button in BILLmanager (requires implementing the
  `Refund` action in `pmrenovax.php` — the structure is ready to add it).
- Additional currencies (the list in `Config()` covers the common ones;
  add more if your merchant accepts them).
- Distribution via the ISPsystem App Marketplace (requires official review).
