# RENOVAX Payments — Conector para Wix (Payment Provider SPI)

Servicio HTTP que implementa el **Wix Payment Provider SPI** (Service Plugin
Interface) para que cualquier tienda **Wix eCommerce / Wix Stores** cobre
con **RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.).

> ⚠️ **Cómo funciona Wix**: a diferencia de WooCommerce o Magento, el plugin
> de pagos de Wix **no se instala en la tienda** — es un servicio HTTP
> externo registrado como "Payment Provider App" en el Wix Dev Center. Wix
> llama a este servicio cuando el comprador paga.

---

## 1. Archivos incluidos

| Archivo | Propósito |
| --- | --- |
| `server.js` | Servicio Express con los 4 endpoints SPI + webhook RENOVAX |
| `lib/renovax.js` | Cliente HTTP de la API merchant de RENOVAX |
| `lib/wix-jws.js` | Verificación de la firma JWS RS256 que envía Wix |
| `package.json` | Dependencias (`express`, `dotenv`) |
| `.env.example` | Plantilla de configuración |

Sin base de datos. Stateless. Despliegable en cualquier Node 18+.

> **Modelo de despliegue**: este servicio es **single-tenant**: una
> instancia por cada merchant RENOVAX. Si vas a vender el conector como
> app pública en el App Market de Wix, requeriría almacenamiento por
> tenant — para uso privado este modelo basta.

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| Node.js | 18+ (usa `fetch` nativo y `node:crypto`) |
| HTTPS público | Obligatorio — Wix y RENOVAX solo entregan webhooks a `https://` |
| Salida HTTPS | TCP 443 hacia `payments.renovax.net` |
| Cuenta Wix | Wix Studio account con acceso al [Dev Center](https://dev.wix.com) |
| Cuenta RENOVAX | Merchant activo en [payments.renovax.net](https://payments.renovax.net) |

---

## 3. Instalación

### Paso 1 — Credenciales RENOVAX

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX:

1. Crea un **Bearer Token**.
2. Copia el **Webhook Secret**.

### Paso 2 — Crear la app en Wix Dev Center

1. Entra a [dev.wix.com](https://dev.wix.com) → **Create New App** → tipo
   **Payment Provider**.
2. Anota el **App ID**.
3. En **Permissions** habilita el scope `Wix Payments / Manage Payment Providers`.
4. En **Service Plugins → Payment Provider** sube el par de claves
   RSA (público + privado). Guarda la pública (PEM) — la usará el SPI
   para verificar las peticiones.
5. Configura los endpoints (URL pública del servicio que vas a desplegar):

| Endpoint | URL |
| --- | --- |
| Connect Account | `https://TU-SERVICIO.com/v1/connect-account` |
| Create Transaction | `https://TU-SERVICIO.com/v1/create-transaction` |
| Refund Transaction | `https://TU-SERVICIO.com/v1/refund-transaction` |

### Paso 3 — Desplegar este servicio

```bash
git clone <este-repo>
cd wix
cp .env.example .env
# edita .env con los valores de los Pasos 1-2
npm install
npm start
```

Despliégalo en un host con HTTPS público (Railway, Fly.io, Render,
DigitalOcean, etc.).

### Paso 4 — Conectar la tienda Wix

1. En tu sitio Wix: **Configuración → Métodos de pago → Conectar más
   métodos** → busca tu app por nombre.
2. Acepta los permisos.
3. Wix llamará a `/v1/connect-account` y, si responde 200, la pasarela
   queda activa en el checkout.

### Paso 5 — Registrar el webhook RENOVAX

En el merchant RENOVAX, configura:

```text
webhook_url: https://TU-SERVICIO.com/webhooks/renovax
```

Listo.

---

## 4. Flujo de pago

1. Cliente hace checkout en Wix y elige **RENOVAX Payments**.
2. Wix llama a `POST /v1/create-transaction` (firmado con JWS RS256).
3. El SPI verifica la firma con `WIX_PUBLIC_KEY`, crea una invoice en
   RENOVAX (`client_remote_id` = `wixTransactionId`) y devuelve:
   ```json
   { "pluginTransactionId": "<uuid>", "redirectUrl": "https://payments.renovax.net/pay/<uuid>" }
   ```
4. Wix redirige al comprador al `pay_url`.
5. Cliente paga en el checkout RENOVAX (Crypto/Stripe/PayPal).
6. RENOVAX envía webhook firmado a `/webhooks/renovax`. El SPI verifica
   HMAC-SHA256, deduplica por `X-Renovax-Event-Id` y notifica a Wix.
7. Wix marca la orden como pagada.

| `event_type` RENOVAX | Acción |
| --- | --- |
| `invoice.paid` | Notifica `APPROVED` a Wix |
| `invoice.overpaid` | Notifica `APPROVED` a Wix (con nota) |
| `invoice.partial` | Notifica `PENDING` (revisión manual) |
| `invoice.expired` | Notifica `DECLINED` |

Refunds: cuando el merchant emite un reembolso desde el panel Wix, Wix
llama a `POST /v1/refund-transaction` y el SPI llama a
`POST /api/v1/merchant/invoices/{id}/refund` en RENOVAX.

> **Nota sobre la notificación Wix**: el SPI v3 de Wix entrega un
> `notifyUrl` en `create-transaction`. Para una integración en producción,
> persiste ese URL (Redis, base de datos, etc.) y haz POST con el estado
> al recibir el webhook RENOVAX. Este scaffold solo loguea — adapta según
> tu modelo de persistencia.

---

## 5. Filtros para los endpoints (firewall / WAF)

Wix firma cada petición con JWS RS256 (verificada vía `WIX_PUBLIC_KEY`) y
RENOVAX firma con HMAC-SHA256. Si tu reverse proxy modifica el body,
**ambas firmas fallarán**.

### 5.1 Permitir IPs

- **RENOVAX**: solicita la lista en [payments.renovax.net/support](https://payments.renovax.net/support).
- **Wix**: las IPs no son fijas; verifica solo por la firma JWS.

### 5.2 Headers que deben pasar sin modificar

| Header | Origen | Propósito |
| --- | --- | --- |
| `X-Renovax-Signature` | RENOVAX | HMAC-SHA256 del body raw |
| `X-Renovax-Event-Id` | RENOVAX | UUID único (idempotencia) |
| `X-Renovax-Event-Type` | RENOVAX | Tipo de evento |
| `Content-Type` | Wix | `text/plain` (Wix envía JWS como string) |
| `Content-Type` | RENOVAX | `application/json` |

### 5.3 Reglas WAF a desactivar

| Regla | Acción |
| --- | --- |
| Buffering / reescritura del body | **Desactivar** |
| Validación / normalización JSON | **Desactivar** |
| Anti-bots / CAPTCHA | **Excluir** los endpoints `/v1/*` y `/webhooks/*` |
| Rate limiting | **Whitelist** Wix y RENOVAX |
| Bloqueo geográfico | **Usar IPs**, no países |

### 5.4 Ejemplo Nginx

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
    # No reescribas headers X-Renovax-*
}
```

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| Wix devuelve `INVALID_REQUEST: invalid_signature` | `WIX_PUBLIC_KEY` no coincide con el par subido al Dev Center, o el WAF mutó el body |
| RENOVAX webhook 401 `invalid_signature` | `RENOVAX_WEBHOOK_SECRET` no coincide o el WAF reescribió el body |
| `connect-account` falla con `CONFIG_ERROR` | Falta `RENOVAX_BEARER_TOKEN` en el `.env` |
| El método no aparece en el checkout Wix | La app no está aprobada / publicada en Dev Center, o el merchant no la conectó |
| Refund devuelve `GENERAL_DECLINE` | El `pluginTransactionId` no coincide con un invoice válido en RENOVAX |

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
