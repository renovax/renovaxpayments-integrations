# RENOVAX Payments — WebX.One Integration

Drop-in that adds **RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado
Pago, etc.) as a balance top-up method on any **WebX.One** install (a DHRU
Fusion alternative for GSM/IMEI unlocking businesses).

> **Why this pattern**: WebX.One is **ionCube-encrypted** and does not expose
> a public Gateway Module API like Dhru or WHMCS do. The standard way to add
> custom gateways to WebX (already used in production by other integrators)
> is to deploy standalone PHP pages that **write directly** to the `webx`
> database to credit `users.balance`. This integration does exactly that,
> optimized for RENOVAX Payments: HMAC-SHA256 signed webhook, atomic
> transactions, idempotency, RENOVAX branding and 6-language i18n.

---

## 1. Files included

| File | Purpose |
| --- | --- |
| `index.php` | RENOVAX-branded checkout (user + amount). |
| `create.php` | Validates input, creates a RENOVAX invoice, redirects to `pay_url`. |
| `webhook.php` | HMAC-SHA256 signed webhook receiver. |
| `status.php` | Read-only JSON endpoint for the success-page AJAX poll. |
| `cleanup.php` | Daily cron that expires stale pending invoices. |
| `sql.sql` | `CREATE TABLE pagos_renovax` (audit + idempotency). |
| `lib/config.example.php` | Configuration template (copy to `config.php`). |
| `lib/bootstrap.php` | Common bootstrap. |
| `lib/db.php` | PDO with prepared statements. |
| `lib/renovax.php` | RENOVAX Payments API HTTP client. |
| `lib/i18n.php` | 6-language dictionary (en, es, fr, pt, ru, ar). |
| `lib/csrf.php` | CSRF tokens for the form. |
| `lib/telegram.php` | Optional admin notifier. |
| `lib/.htaccess` + `lib/index.php` | Block HTTP access to the `lib/` folder. |
| `assets/icon.png` + `assets/style.css` | RENOVAX isotype + minimal styles. |

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| WebX.One | Any version with a `webx` database and `users` table (`id`, `username`, `email`, `balance`) |
| PHP | 7.4+ with `pdo_mysql`, `curl`, `hash`, `json` extensions |
| MySQL/MariaDB | 5.7+ / 10.3+ with `users` table accessible |
| HTTPS | Mandatory — RENOVAX only delivers webhooks to `https://` URLs |
| Outbound HTTPS | TCP 443 open to `payments.renovax.net` |
| RENOVAX Payments account | Active merchant at [payments.renovax.net](https://payments.renovax.net) with at least one configured payment method |

---

## 3. Installation

### Step 1 — Get RENOVAX Payments credentials

In **Merchants → (your merchant) → Edit → API Tokens** on RENOVAX Payments:

1. Create a **Bearer Token** (shown once — copy it).
2. Copy the **Webhook Secret**.

### Step 2 — Create the audit table

Run `sql.sql` against the `webx` database:

```bash
mysql -u root -p webx < sql.sql
```

Creates the `pagos_renovax` table (does not touch `users`).

### Step 3 — Upload the files

Upload the `renovax/` folder to your WebX public root, e.g.
`/var/www/html/renovax/`. Keep the directory layout.

```bash
chmod 755 /var/www/html/renovax
chmod 644 /var/www/html/renovax/*.php /var/www/html/renovax/lib/*.php
```

### Step 4 — Configure `lib/config.php`

```bash
cp lib/config.example.php lib/config.php
chmod 640 lib/config.php
chown www-data:www-data lib/config.php   # adjust to your web server user
```

Edit `lib/config.php` with:

- WebX MySQL credentials (`db.user`, `db.pass`).
- Bearer Token + Webhook Secret from RENOVAX Payments.
- Charging currency (`renovax.currency`, default `USD`).
- Amount range (`min_amount` / `max_amount`).
- Optional Telegram (leave `enabled: false` if unused).
- Site name + public URL of the drop-in.

### Step 5 — Register the webhook URL

In **Merchants → (your merchant) → Edit** on RENOVAX Payments, set:

```text
webhook_url: https://YOUR-WEBX-DOMAIN.com/renovax/webhook.php
```

### Step 6 — Link the checkout from the WebX panel

Add a "Top up balance" link in the customer panel pointing to:

```text
https://YOUR-WEBX-DOMAIN.com/renovax/
```

(Language is auto-detected from the customer's browser; you can also force
one with `?lang=es`, `?lang=fr`, etc.)

### Step 7 — Housekeeping cron (recommended)

```cron
0 3 * * * /usr/bin/php /var/www/html/renovax/cleanup.php >> /var/log/renovax-cleanup.log 2>&1
```

Marks pending invoices older than 24 h (configurable in
`config.limits.expire_after_hours`) as `expired`.

Done.

---

## 4. Payment flow

1. Customer opens `/renovax/` → sees the checkout: user + amount + button.
2. Submit → `create.php`:
   - Verifies the CSRF token.
   - Validates user (accepts email **or** username) + amount in range.
   - Applies rate limits: max N pending per user, max M creations per IP in 10 min.
   - Creates a RENOVAX invoice with `client_remote_id = "webx-{user_id}-{ts}"`.
   - INSERT into `pagos_renovax` with `status='pending'`.
   - Redirects to `pay_url`.
3. Customer pays on the RENOVAX checkout (Crypto / Stripe / PayPal).
4. RENOVAX → POST `/renovax/webhook.php` with headers
   `X-Renovax-Signature`, `X-Renovax-Event-Id`, `X-Renovax-Event-Type`.
5. `webhook.php`:
   - Verifies HMAC-SHA256 against `webhook_secret`.
   - Cross-check: `invoice_id` must exist in `pagos_renovax`.
   - Cross-check: `metadata.webx_user_id` must match the stored value.
   - Idempotency: rejects re-deliveries with the same `event_id`.
   - **Atomic transaction**: `UPDATE users SET balance = balance + X` + `UPDATE pagos_renovax`.
   - Fast 200 OK under `ignore_user_abort(true)`.
   - (Optional) Telegram notification.
6. Customer returns to `/renovax/?status=ok` and sees a success message + an
   AJAX poll to `status.php` that refreshes when `pagos_renovax.status = paid`.

| RENOVAX `event_type` | WebX action |
| --- | --- |
| `invoice.paid` | `users.balance += amount_net_fiat`; `status='paid'` |
| `invoice.overpaid` | Same + status `overpaid`; Telegram alert |
| `invoice.partial` | Recorded only; no credit; Telegram alert (manual review) |
| `invoice.expired` | Marks `status='expired'`; no credit |

---

## 5. Webhook filters (firewall / WAF)

`/renovax/webhook.php` receives HMAC-SHA256 signed webhooks. If your WAF,
reverse proxy or firewall modifies the request, **every signature will fail
with `401 invalid_signature`**.

### 5.1 Allow RENOVAX Payments IPs

Request the current egress IP list at
[payments.renovax.net/support](https://payments.renovax.net/support) and
whitelist them for `POST /renovax/webhook.php`.

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
| Body size limit | 1 MB is plenty |

### 5.4 Configuration examples

**Cloudflare** — Configuration Rule for `yourdomain.com/renovax/webhook.php`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx**:

```nginx
location = /renovax/webhook.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/renovax/webhook.php;
}

# Block public access to lib/
location ~ /renovax/lib/ {
    deny all;
    return 403;
}
```

**Apache** — the bundled `.htaccess` in `lib/` already blocks access. If you
run mod_security, exclude it only for `webhook.php`:

```apache
<Files "webhook.php">
    SecRuleEngine Off
</Files>
```

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` in logs | WAF mutates the body or `webhook_secret` does not match |
| Balance never credits | Webhook URL not registered in RENOVAX or endpoint not public |
| `RENOVAX authentication failed` on invoice creation | Wrong or expired `bearer_token` |
| Customer always sees "Invalid user or amount" | The email/username does not exist in `webx.users` or amount out of range |
| AJAX poll never updates | Webhook didn't arrive (check logs) or `pagos_renovax.status` was not updated |
| `db_transaction_failed` in logs | MySQL permissions: the user needs `UPDATE` on `webx.users` |
| 403 on `/renovax/lib/config.php` from a browser | ✅ Correct — the `.htaccess` is working |

Enable detailed logs with `tail -f /var/log/apache2/error.log | grep renovax-payments`
(or your PHP error log equivalent).

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)

---

## Disclaimer

This integration writes directly to the `users` table of the `webx`
database. WebX.One **does not document this practice** and its code is
ionCube-encrypted. If you upgrade WebX to a version that changes the
`users.balance` schema, this integration must be reviewed. Tested against
the structure observed in production WebX installations.

## Out of scope v1

- **Automatic refunds**: the RENOVAX API supports refunds; a protected
  admin button (`refund.php`) is planned for v1.1.
- **Embedding inside the WebX panel**: requires modifying WebX templates,
  which are encrypted. Use an external link for now.
- **Auto-detection of WebX credentials**: impossible because of ionCube
  (encrypted config). Manual configuration in `lib/config.php`.
