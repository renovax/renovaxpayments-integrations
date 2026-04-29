# RENOVAX Payments — Conector para Shopify

Conector para que cualquier tienda **Shopify** cobre con **RENOVAX Payments**
(crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). El cliente paga en el
checkout hospedado de RENOVAX y, cuando el pago se confirma, este conector
marca el pedido en Shopify como pagado vía Admin API.

> ⚠️ **Limitación de Shopify**: Shopify solo permite pasarelas de pago
> personalizadas en planes **Shopify Plus** (vía Payments Apps API). Para el
> resto de planes (Basic, Shopify, Advanced) usamos el patrón estándar de la
> industria: **Manual payment method + Admin API**, igual que CoinGate,
> Coinbase Commerce, NOWPayments, etc.

---

## 1. Archivos incluidos

| Archivo | Propósito |
| --- | --- |
| `server.js` | Servicio Express con los dos endpoints de webhook |
| `lib/renovax.js` | Cliente HTTP de la API merchant de RENOVAX |
| `lib/shopify.js` | Cliente Admin API GraphQL de Shopify |
| `package.json` | Dependencias (`express`, `dotenv`) |
| `.env.example` | Plantilla de configuración |

Sin base de datos. Stateless. Despliegable en cualquier Node 18+.

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| Node.js | 18+ (usa `fetch` nativo y `node:crypto`) |
| HTTPS público | Obligatorio — RENOVAX y Shopify solo entregan webhooks a `https://` |
| Salida HTTPS | TCP 443 hacia `payments.renovax.net` y `*.myshopify.com` |
| Tienda Shopify | Cualquier plan; ideal con **Custom App** habilitada |
| Cuenta RENOVAX | Merchant activo en [payments.renovax.net](https://payments.renovax.net) |

---

## 3. Instalación

### Paso 1 — Credenciales RENOVAX

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX:

1. Crea un **Bearer Token** (se muestra una sola vez).
2. Copia el **Webhook Secret**.

### Paso 2 — Custom App en Shopify

En el admin de Shopify: **Apps → Develop apps → Create an app**.

1. Nombra la app `RENOVAX Connector`.
2. **Configuration → Admin API integration → Configure**.
   Activa scopes: `read_orders`, `write_orders`, `write_draft_orders`.
3. **Install app** → copia el **Admin API access token** (`shpat_…`).

### Paso 3 — Manual payment method

En **Settings → Payments → Manual payment methods → Create custom payment method**:

| Campo | Valor |
| --- | --- |
| **Custom payment method name** | `RENOVAX Payments` |
| **Additional details** | `Crypto, Stripe, PayPal — pago seguro tras realizar el pedido.` |
| **Payment instructions** | `Recibirás un email con el enlace de pago RENOVAX en segundos.` |

> El nombre debe ser exactamente `RENOVAX Payments` — el conector lo usa para
> filtrar pedidos.

### Paso 4 — Desplegar este servicio

```bash
git clone <este-repo>
cd shopify
cp .env.example .env
# edita .env con los valores de los pasos 1-2
npm install
npm start
```

Despliégalo en un servidor con HTTPS público (Railway, Fly.io, Render,
DigitalOcean, etc.). Anota la URL pública, p. ej. `https://renovax-shopify.midominio.com`.

### Paso 5 — Registrar webhooks

**a) Shopify → este servicio**: en **Settings → Notifications → Webhooks**
crea un webhook:

| Campo | Valor |
| --- | --- |
| **Event** | `Order creation` |
| **Format** | JSON |
| **URL** | `https://TU-SERVICIO.com/webhooks/shopify/orders-create` |
| **Webhook API version** | `2024-10` (o la más reciente) |

Después haz click en **Webhooks signing secret** (está al pie de la página) y
pégalo en `SHOPIFY_WEBHOOK_SECRET` del `.env`.

**b) RENOVAX → este servicio**: en el merchant de RENOVAX, configura:

```text
webhook_url: https://TU-SERVICIO.com/webhooks/renovax
```

Listo.

---

## 4. Flujo de pago

1. Cliente hace checkout en Shopify y elige **RENOVAX Payments** (manual).
2. Shopify crea el pedido en estado `pending` y dispara `orders/create`.
3. Este servicio recibe el webhook, verifica HMAC, y crea una invoice
   RENOVAX con `client_remote_id = order.id`.
4. Añade nota al pedido con el `pay_url`. Shopify envía el email automático
   de "Order confirmed" donde el merchant puede incluir el enlace, o el
   conector lo envía por separado vía email transaccional.
5. Cliente paga en el checkout RENOVAX (Crypto/Stripe/PayPal).
6. RENOVAX envía webhook firmado → este servicio marca el pedido como
   `paid` vía `orderMarkAsPaid` GraphQL.

| `event_type` RENOVAX | Acción Shopify |
| --- | --- |
| `invoice.paid` | `orderMarkAsPaid` + nota con bruto/neto/comisión/tx |
| `invoice.overpaid` | `orderMarkAsPaid` + nota destacada |
| `invoice.partial` | Solo nota — revisión manual |
| `invoice.expired` | `orderCancel` (con restock) |

---

## 5. Filtros para los Webhooks (firewall / WAF)

Los endpoints reciben webhooks firmados con **HMAC-SHA256**. Si tu reverse
proxy o firewall modifica el body, **todas las firmas fallarán con `401
invalid_signature`**.

### 5.1 Permitir IPs

- **RENOVAX**: solicita la lista en [payments.renovax.net/support](https://payments.renovax.net/support).
- **Shopify**: las IPs cambian; mejor verifica solo por HMAC en `X-Shopify-Hmac-Sha256`.

### 5.2 Headers que deben pasar sin modificar

| Header | Origen | Propósito |
| --- | --- | --- |
| `X-Renovax-Signature` | RENOVAX | HMAC-SHA256 del body raw |
| `X-Renovax-Event-Id` | RENOVAX | UUID único (idempotencia) |
| `X-Renovax-Event-Type` | RENOVAX | Tipo de evento |
| `X-Shopify-Hmac-Sha256` | Shopify | HMAC base64 del body raw |
| `X-Shopify-Topic` | Shopify | Tipo de webhook |
| `Content-Type` | Ambos | `application/json` |

### 5.3 Reglas WAF a desactivar para `/webhooks/*`

| Regla | Acción |
| --- | --- |
| Buffering / reescritura del body | **Desactivar** |
| Validación / normalización JSON | **Desactivar** |
| Anti-bots / CAPTCHA | **Excluir** |
| Rate limiting | **Whitelist** RENOVAX y Shopify |
| Bloqueo geográfico | **Usar IPs**, no países |

### 5.4 Ejemplo Nginx

```nginx
location /webhooks/ {
    client_max_body_size 1m;
    proxy_request_buffering off;
    proxy_pass http://localhost:3000;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    # No reescribas headers X-Renovax-* ni X-Shopify-*
}
```

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` (RENOVAX) | El WAF modifica el body o el `RENOVAX_WEBHOOK_SECRET` no coincide |
| `401 invalid_signature` (Shopify) | `SHOPIFY_WEBHOOK_SECRET` no es el "Webhooks signing secret" actual |
| El pedido no se marca pagado | Falta scope `write_orders` en la Custom App, o el `orderGid` en metadata es incorrecto |
| `ignored: not_renovax_gateway` en logs | El nombre del Manual payment method no es exactamente `RENOVAX Payments` |
| `RENOVAX authentication failed` | `RENOVAX_BEARER_TOKEN` incorrecto o caducado |

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
