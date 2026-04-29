# RENOVAX Payments — Saleor App

Native **Saleor 3.13+** App that adds **RENOVAX Payments** as a payment
gateway via the official **Transactions API**. Customers pay on the
RENOVAX hosted checkout (Crypto, Stripe, PayPal, PIX, Mercado Pago,
etc.) and the transaction closes automatically in Saleor.

> ⚠️ **Example code only.** The official maintained version lives at
> [github.com/renovax/renovaxpayments-integrations/tree/main/saleor](https://github.com/renovax/renovaxpayments-integrations/tree/main/saleor).
> Always pull the latest before deploying to production.

---

## 1. Highlights

- **Multi-tenant**: a single deployment serves N Saleor stores. Each
  store sets its own RENOVAX bearer/secret from the Dashboard panel,
  not from `.env`.
- **Transactions API**: native gateway in the checkout, not a "manual
  payment method" workaround.
- **6 languages**: the checkout locale (`en`, `es`, `fr`, `pt`, `ru`,
  `ar`) is forwarded to RENOVAX so the hosted checkout matches the
  customer's language.
- **Refunds and cancellations** straight from the Saleor Dashboard.
- **Pluggable APL**: `file` for dev, `redis` for production (shares the
  project Redis with prefixes `saleor:apl:*` / `saleor:cfg:*`).
- **1-click deploy**: `Dockerfile`, `render.yaml`, `railway.json`.
- **Saleor App Store** submission ready.

---

## 2. Files

| Path | Purpose |
| --- | --- |
| `server.js` | Express app, mounts webhooks and UI |
| `lib/manifest.js` | Saleor manifest builder |
| `lib/jws-verify.js` | `Saleor-Signature` JWS verification (RS256 with cached JWKS) |
| `lib/apl.js` | App Persistence Layer (file / redis) |
| `lib/config-store.js` | Per-tenant RENOVAX configuration |
| `lib/renovax.js` | RENOVAX merchant API HTTP client |
| `lib/saleor-client.js` | Minimal GraphQL client (`transactionEventReport`) |
| `lib/event-map.js` | RENOVAX `event_type` → Saleor `TransactionEventType` |
| `lib/locale.js` | `LanguageCodeEnum` → 2-letter ISO code |
| `webhooks/*.js` | 6 Saleor handlers + 1 RENOVAX callback |
| `ui/configuration.{html,js}` | Dashboard panel (Bootstrap + `.rnx-brand`) |
| `tests/*.test.js` | `vitest` unit tests |
| `deploy/Dockerfile` | Production image (Node 20 alpine) |
| `deploy/render.yaml` | Render 1-click deploy |
| `deploy/railway.json` | Railway 1-click deploy |

---

## 3. Requirements

| Resource | Detail |
| --- | --- |
| Node.js | 18+ |
| Public HTTPS | Mandatory — Saleor and RENOVAX only deliver webhooks to `https://` |
| Saleor | 3.13+ (Cloud or self-hosted) |
| Redis | Optional, recommended in production for multi-tenant |
| RENOVAX account | Active at [payments.renovax.net](https://payments.renovax.net) |

---

## 4. Installation

### Step 1 — Deploy the service

**Option A — Render** (1 click): connect the repo, Render reads
`deploy/render.yaml`.

**Option B — Railway** (1 click): `railway up` with `deploy/railway.json`.

**Option C — Docker**:

```bash
docker build -f deploy/Dockerfile -t renovax-saleor .
docker run -d -p 3000:3000 \
  -e APP_URL=https://renovax-saleor.yourdomain.com \
  -e APL=redis -e REDIS_URL=redis://redis:6379/0 \
  renovax-saleor
```

**Option D — Manual**:

```bash
cd docs/integrations/saleor
cp .env.example .env  # edit APP_URL
npm install
npm start
```

Sit it behind an HTTPS proxy (Caddy, Nginx, Cloudflare).

### Step 2 — Install the App in Saleor

In the Saleor Dashboard:

1. **Apps → Install external app**.
2. Paste: `https://YOUR-APP.com/api/manifest`.
3. Accept the permissions (`HANDLE_PAYMENTS`).
4. Done — the App is installed.

### Step 3 — Configure your RENOVAX credentials

1. In Saleor: **Apps → RENOVAX Payments → Open**.
2. The embedded panel opens. Paste:
   - **Bearer token** (from RENOVAX → Merchants → API Tokens).
   - **Webhook secret** (same screen).
3. Click **Verify and save**. The service validates the token against
   RENOVAX before persisting it.

### Step 4 — Register the webhook in RENOVAX

In your RENOVAX merchant settings, configure:

```text
webhook_url: https://YOUR-APP.com/api/webhooks/renovax
```

Done. RENOVAX Payments now appears as a payment method at checkout.

---

## 5. Payment flow

1. Customer reaches checkout and picks **RENOVAX Payments**.
2. Storefront calls `transactionInitialize` → Saleor sends
   `TRANSACTION_INITIALIZE_SESSION` to this service.
3. We create a RENOVAX invoice and reply with `CHARGE_REQUEST`,
   `pay_url`, `pspReference = invoice.id`.
4. Storefront redirects the customer to `pay_url`.
5. Customer pays (Crypto/Stripe/PayPal/etc.).
6. RENOVAX sends a signed webhook to `/api/webhooks/renovax`.
7. We verify HMAC, resolve the tenant, call `transactionEventReport`
   in Saleor → order moves to `Paid`.

| RENOVAX `event_type` | Saleor event |
| --- | --- |
| `invoice.paid` | `CHARGE_SUCCESS` |
| `invoice.overpaid` | `CHARGE_SUCCESS` + OVERPAID note |
| `invoice.partial` | `CHARGE_FAILURE` (manual review) |
| `invoice.expired` | `CANCEL_SUCCESS` |
| `invoice.refunded` | `REFUND_SUCCESS` |

---

## 6. Multi-tenant

Each `Saleor-Api-Url` keeps its own configuration in the APL:

```
APL backend (file or redis):
  saleor:apl:https://store-a.saleor.cloud/graphql/   → { auth_token, ... }
  saleor:apl:https://store-b.saleor.cloud/graphql/   → { auth_token, ... }
  saleor:cfg:https://store-a.saleor.cloud/graphql/   → { renovaxBearer, ... }
  saleor:cfg:https://store-b.saleor.cloud/graphql/   → { renovaxBearer, ... }
```

The RENOVAX callback carries `metadata.saleor_api_url` on every invoice,
which we use to resolve the right tenant.

---

## 7. Firewall / WAF

Signed endpoints:

| Header | Origin | Use |
| --- | --- | --- |
| `Saleor-Signature` | Saleor | RS256 JWS over the raw body |
| `Saleor-Api-Url` | Saleor | Identifies the tenant |
| `Saleor-Event` | Saleor | Webhook type |
| `X-Renovax-Signature` | RENOVAX | HMAC-SHA256 over the raw body |
| `X-Renovax-Event-Id` | RENOVAX | Idempotency UUID |
| `X-Renovax-Event-Type` | RENOVAX | Event type |

**Any WAF that rewrites the body breaks every signature.** Disable
buffering / JSON normalization / rate-limit / CAPTCHA on
`/api/webhooks/*`.

```nginx
location /api/webhooks/ {
    client_max_body_size 2m;
    proxy_request_buffering off;
    proxy_pass http://localhost:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
}
```

---

## 8. Tests

```bash
npm test
```

Covers:
- `event_type` → Saleor mapping.
- Locale mapping.
- HMAC verification and idempotency on the RENOVAX callback.
- Saleor JWS signature verification (negative cases).
- Manifest builder.

---

## 9. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401 invalid_signature` Saleor | WAF rewrites the body, or JWKS not reachable from the server |
| `401 invalid_signature` RENOVAX | Webhook secret in the panel does not match the one in RENOVAX |
| `unknown_tenant` in logs | `metadata.saleor_api_url` missing on the invoice — make sure the App is the one creating it |
| `token_verification_failed` on save | Expired bearer or wrong `renovaxApiBase` |
| Gateway not visible at checkout | `HANDLE_PAYMENTS` permission not granted at install time |

---

## 10. Publishing on the Saleor App Store

Once tested, list the App on the [official Saleor App Store](https://apps.saleor.io/):

1. Fork the public RENOVAX repo.
2. Submit the manifest URL via the App Store form.
3. Saleor reviews and publishes — free distribution to the whole network.

---

## 11. Support

[payments.renovax.net/support](https://payments.renovax.net/support)
