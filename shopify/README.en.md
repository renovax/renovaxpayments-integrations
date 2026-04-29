# RENOVAX Payments — Shopify Connector

Connector that lets any **Shopify** store charge through **RENOVAX Payments**
(crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). The customer pays on the
RENOVAX hosted checkout and, once confirmed, this connector marks the order
as paid via the Admin API.

> ⚠️ **Shopify limitation**: Shopify only allows custom payment gateways on
> **Shopify Plus** plans (via the Payments Apps API). For all other plans
> (Basic, Shopify, Advanced) we use the industry-standard pattern: **Manual
> payment method + Admin API**, the same approach used by CoinGate, Coinbase
> Commerce, NOWPayments, etc.

---

## 1. Files included

| File | Purpose |
| --- | --- |
| `server.js` | Express service with the two webhook endpoints |
| `lib/renovax.js` | RENOVAX merchant API HTTP client |
| `lib/shopify.js` | Shopify Admin GraphQL API client |
| `package.json` | Dependencies (`express`, `dotenv`) |
| `.env.example` | Configuration template |

No database. Stateless. Deployable on any Node 18+.

---

## 2. Server requirements

| Requirement | Detail |
| --- | --- |
| Node.js | 18+ (uses native `fetch` and `node:crypto`) |
| Public HTTPS | Mandatory — RENOVAX and Shopify only deliver webhooks to `https://` |
| Outbound HTTPS | TCP 443 to `payments.renovax.net` and `*.myshopify.com` |
| Shopify store | Any plan; ideally with a Custom App enabled |
| RENOVAX account | Active merchant at [payments.renovax.net](https://payments.renovax.net) |

---

## 3. Installation

### Step 1 — RENOVAX credentials

In **Merchants → (your merchant) → Edit → API Tokens** on RENOVAX:

1. Create a **Bearer Token** (shown once).
2. Copy the **Webhook Secret**.

### Step 2 — Shopify Custom App

In the Shopify admin: **Apps → Develop apps → Create an app**.

1. Name the app `RENOVAX Connector`.
2. **Configuration → Admin API integration → Configure**.
   Enable scopes: `read_orders`, `write_orders`, `write_draft_orders`.
3. **Install app** → copy the **Admin API access token** (`shpat_…`).

### Step 3 — Manual payment method

In **Settings → Payments → Manual payment methods → Create custom payment method**:

| Field | Value |
| --- | --- |
| **Custom payment method name** | `RENOVAX Payments` |
| **Additional details** | `Crypto, Stripe, PayPal — secure payment after placing the order.` |
| **Payment instructions** | `You will receive an email with the RENOVAX payment link in seconds.` |

> The name must be exactly `RENOVAX Payments` — the connector uses it to
> filter orders.

### Step 4 — Deploy this service

```bash
git clone <this-repo>
cd shopify
cp .env.example .env
# edit .env with the values from steps 1-2
npm install
npm start
```

Deploy it on any host with public HTTPS (Railway, Fly.io, Render,
DigitalOcean, etc.). Note the public URL, e.g. `https://renovax-shopify.yourdomain.com`.

### Step 5 — Register webhooks

**a) Shopify → this service**: in **Settings → Notifications → Webhooks**
create a webhook:

| Field | Value |
| --- | --- |
| **Event** | `Order creation` |
| **Format** | JSON |
| **URL** | `https://YOUR-SERVICE.com/webhooks/shopify/orders-create` |
| **Webhook API version** | `2024-10` (or latest) |

Then click **Webhooks signing secret** at the bottom of the page and paste
it into `SHOPIFY_WEBHOOK_SECRET` in `.env`.

**b) RENOVAX → this service**: in the RENOVAX merchant settings, set:

```text
webhook_url: https://YOUR-SERVICE.com/webhooks/renovax
```

Done.

---

## 4. Payment flow

1. Customer checks out on Shopify and picks **RENOVAX Payments** (manual).
2. Shopify creates the order with status `pending` and fires `orders/create`.
3. This service receives the webhook, verifies HMAC, and creates a RENOVAX
   invoice with `client_remote_id = order.id`.
4. Adds an order note with the `pay_url`. Shopify sends its automatic
   "Order confirmed" email where the merchant can include the link, or the
   connector emails it separately.
5. Customer pays on the RENOVAX hosted checkout (Crypto/Stripe/PayPal).
6. RENOVAX sends a signed webhook → this service marks the order as
   `paid` via the `orderMarkAsPaid` GraphQL mutation.

| RENOVAX `event_type` | Shopify action |
| --- | --- |
| `invoice.paid` | `orderMarkAsPaid` + note with gross/net/fee/tx |
| `invoice.overpaid` | `orderMarkAsPaid` + flagged note |
| `invoice.partial` | Note only — manual review |
| `invoice.expired` | `orderCancel` (with restock) |

---

## 5. Webhook filters (firewall / WAF)

The endpoints receive **HMAC-SHA256** signed webhooks. If your reverse proxy
or firewall modifies the body, **every signature will fail with `401
invalid_signature`**.

### 5.1 Allow IPs

- **RENOVAX**: request the list at [payments.renovax.net/support](https://payments.renovax.net/support).
- **Shopify**: the IPs change frequently; rely on the HMAC header
  `X-Shopify-Hmac-Sha256` instead.

### 5.2 Headers that must pass through unmodified

| Header | Source | Purpose |
| --- | --- | --- |
| `X-Renovax-Signature` | RENOVAX | HMAC-SHA256 of the raw body |
| `X-Renovax-Event-Id` | RENOVAX | Unique UUID (idempotency) |
| `X-Renovax-Event-Type` | RENOVAX | Event type |
| `X-Shopify-Hmac-Sha256` | Shopify | base64 HMAC of the raw body |
| `X-Shopify-Topic` | Shopify | Webhook type |
| `Content-Type` | Both | `application/json` |

### 5.3 WAF rules to disable for `/webhooks/*`

| Rule | Action |
| --- | --- |
| Body buffering / rewriting | **Disable** |
| JSON validation / normalization | **Disable** |
| Anti-bot / CAPTCHA | **Exclude** |
| Rate limiting | **Whitelist** RENOVAX and Shopify |
| Geo-blocking | **Use IPs**, not countries |

### 5.4 Nginx example

```nginx
location /webhooks/ {
    client_max_body_size 1m;
    proxy_request_buffering off;
    proxy_pass http://localhost:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    # Do not rewrite X-Renovax-* or X-Shopify-* headers
}
```

---

## 6. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` (RENOVAX) | WAF mutates the body, or `RENOVAX_WEBHOOK_SECRET` does not match |
| `401 invalid_signature` (Shopify) | `SHOPIFY_WEBHOOK_SECRET` is not the current "Webhooks signing secret" |
| Order is never marked paid | Missing `write_orders` scope on the Custom App, or the `orderGid` in metadata is wrong |
| `ignored: not_renovax_gateway` in logs | The Manual payment method name is not exactly `RENOVAX Payments` |
| `RENOVAX authentication failed` | `RENOVAX_BEARER_TOKEN` is wrong or expired |

---

## 7. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
