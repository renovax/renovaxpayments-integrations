# RENOVAX Payments — Wix Connector (Payment Provider SPI)

HTTP service implementing the **Wix Payment Provider SPI** (Service Plugin
Interface) so any **Wix eCommerce / Wix Stores** site can charge through
**RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.).

> ⚠️ **How Wix works**: unlike WooCommerce or Magento, the Wix payment
> plugin **is not installed in the store** — it is an external HTTP service
> registered as a "Payment Provider App" in the Wix Dev Center. Wix calls
> this service when the buyer pays.

---

## 1. Files included

| File | Purpose |
| --- | --- |
| `server.js` | Express service with the 4 SPI endpoints + RENOVAX webhook |
| `lib/renovax.js` | RENOVAX merchant API HTTP client |
| `lib/wix-jws.js` | Verifies the JWS RS256 signature Wix sends |
| `package.json` | Dependencies (`express`, `dotenv`) |
| `.env.example` | Configuration template |

No database. Stateless. Deployable on any Node 18+.

> **Deployment model**: this service is **single-tenant** — one instance
> per RENOVAX merchant. To list it as a public app on the Wix App Market
> you would need per-tenant storage; for private use this model is enough.

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| Node.js | 18+ (uses native `fetch` and `node:crypto`) |
| Public HTTPS | Mandatory — Wix and RENOVAX only deliver to `https://` |
| Outbound HTTPS | TCP 443 to `payments.renovax.net` |
| Wix account | Wix Studio account with [Dev Center](https://dev.wix.com) access |
| RENOVAX account | Active merchant at [payments.renovax.net](https://payments.renovax.net) |

---

## 3. Installation

### Step 1 — RENOVAX credentials

In **Merchants → (your merchant) → Edit → API Tokens** on RENOVAX:

1. Create a **Bearer Token**.
2. Copy the **Webhook Secret**.

### Step 2 — Create the Wix Dev Center app

1. Go to [dev.wix.com](https://dev.wix.com) → **Create New App** → type
   **Payment Provider**.
2. Note the **App ID**.
3. Under **Permissions** enable the scope `Wix Payments / Manage Payment
   Providers`.
4. Under **Service Plugins → Payment Provider** upload an RSA key pair
   (public + private). Keep the public PEM — the SPI uses it to verify
   incoming requests.
5. Configure the endpoint URLs (public URL of the service you'll deploy):

| Endpoint | URL |
| --- | --- |
| Connect Account | `https://YOUR-SERVICE.com/v1/connect-account` |
| Create Transaction | `https://YOUR-SERVICE.com/v1/create-transaction` |
| Refund Transaction | `https://YOUR-SERVICE.com/v1/refund-transaction` |

### Step 3 — Deploy this service

```bash
git clone <this-repo>
cd wix
cp .env.example .env
# edit .env with the values from steps 1-2
npm install
npm start
```

Deploy on any host with public HTTPS (Railway, Fly.io, Render, DigitalOcean,
etc.).

### Step 4 — Connect the Wix store

1. In your Wix site: **Settings → Accept Payments → Connect Other Methods**
   → search for your app.
2. Approve permissions.
3. Wix will call `/v1/connect-account` and, if it returns 200, the gateway
   becomes active at checkout.

### Step 5 — Register the RENOVAX webhook

In the RENOVAX merchant settings:

```text
webhook_url: https://YOUR-SERVICE.com/webhooks/renovax
```

Done.

---

## 4. Payment flow

1. Customer checks out on Wix and picks **RENOVAX Payments**.
2. Wix calls `POST /v1/create-transaction` (signed as JWS RS256).
3. The SPI verifies the signature with `WIX_PUBLIC_KEY`, creates an invoice
   on RENOVAX (`client_remote_id` = `wixTransactionId`) and returns:
   ```json
   { "pluginTransactionId": "<uuid>", "redirectUrl": "https://payments.renovax.net/pay/<uuid>" }
   ```
4. Wix redirects the buyer to the `pay_url`.
5. Customer pays on the RENOVAX hosted checkout (Crypto/Stripe/PayPal).
6. RENOVAX POSTs a signed webhook to `/webhooks/renovax`. The SPI verifies
   HMAC-SHA256, deduplicates by `X-Renovax-Event-Id`, and notifies Wix.
7. Wix marks the order as paid.

| RENOVAX `event_type` | Action |
| --- | --- |
| `invoice.paid` | Notify `APPROVED` to Wix |
| `invoice.overpaid` | Notify `APPROVED` to Wix (with note) |
| `invoice.partial` | Notify `PENDING` (manual review) |
| `invoice.expired` | Notify `DECLINED` |

Refunds: when the merchant issues a refund from the Wix admin, Wix calls
`POST /v1/refund-transaction` and the SPI calls
`POST /api/v1/merchant/invoices/{id}/refund` on RENOVAX.

> **Note on Wix notification**: Wix SPI v3 delivers a `notifyUrl` in
> `create-transaction`. For production, persist that URL (Redis, DB, etc.)
> and POST the status to it when the RENOVAX webhook arrives. This
> scaffold only logs — adapt to your persistence model.

---

## 5. Endpoint filters (firewall / WAF)

Wix signs every request with JWS RS256 (verified via `WIX_PUBLIC_KEY`) and
RENOVAX signs with HMAC-SHA256. If your reverse proxy modifies the body,
**both signatures will fail**.

### 5.1 Allow IPs

- **RENOVAX**: request the list at [payments.renovax.net/support](https://payments.renovax.net/support).
- **Wix**: IPs are not fixed; rely on the JWS signature instead.

### 5.2 Headers that must pass through unmodified

| Header | Source | Purpose |
| --- | --- | --- |
| `X-Renovax-Signature` | RENOVAX | HMAC-SHA256 of the raw body |
| `X-Renovax-Event-Id` | RENOVAX | Unique UUID (idempotency) |
| `X-Renovax-Event-Type` | RENOVAX | Event type |
| `Content-Type` | Wix | `text/plain` (Wix sends the JWS as a string) |
| `Content-Type` | RENOVAX | `application/json` |

### 5.3 WAF rules to disable

| Rule | Action |
| --- | --- |
| Body buffering / rewriting | **Disable** |
| JSON validation / normalization | **Disable** |
| Anti-bot / CAPTCHA | **Exclude** the `/v1/*` and `/webhooks/*` routes |
| Rate limiting | **Whitelist** Wix and RENOVAX |
| Geo-blocking | **Use IPs**, not countries |

### 5.4 Nginx example

```nginx
location /v1/ {
    client_max_body_size 1m;
    proxy_request_buffering off;
    proxy_pass http://localhost:3000;
}

location /webhooks/ {
    client_max_body_size 1m;
    proxy_request_buffering off;
    proxy_pass http://localhost:3000;
    # Do not rewrite X-Renovax-* headers
}
```

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Wix returns `INVALID_REQUEST: invalid_signature` | `WIX_PUBLIC_KEY` does not match the Dev Center key pair, or WAF mutated the body |
| RENOVAX webhook 401 `invalid_signature` | `RENOVAX_WEBHOOK_SECRET` mismatch or WAF rewrote the body |
| `connect-account` fails with `CONFIG_ERROR` | Missing `RENOVAX_BEARER_TOKEN` in `.env` |
| Method does not appear at Wix checkout | App is not approved/published in Dev Center, or merchant did not connect it |
| Refund returns `GENERAL_DECLINE` | The `pluginTransactionId` does not match a valid RENOVAX invoice |

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
