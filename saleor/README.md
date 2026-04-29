# RENOVAX Payments — Saleor App

App nativa para **Saleor 3.13+** que añade **RENOVAX Payments** como
gateway de pago usando la **Transactions API** oficial. El cliente paga
en el checkout hosted de RENOVAX (Crypto, Stripe, PayPal, PIX, Mercado
Pago, etc.) y la transacción se cierra automáticamente en Saleor.

> ⚠️ **Esto es código de ejemplo**. La versión oficial mantenida vive en
> [github.com/renovax/renovaxpayments-integrations/tree/main/saleor](https://github.com/renovax/renovaxpayments-integrations/tree/main/saleor).
> Descarga siempre la última antes de instalar en producción.

---

## 1. Características

- **Multi-tenant**: un solo despliegue sirve a N tiendas Saleor. Cada
  tienda configura su propio bearer/secret RENOVAX desde el panel del
  Dashboard, no desde `.env`.
- **Transactions API**: integración nativa, no truco con "manual payment
  method". Aparece como gateway propio en el checkout.
- **6 idiomas**: el locale del checkout (`en`, `es`, `fr`, `pt`, `ru`,
  `ar`) se envía a RENOVAX para que el hosted checkout salga en el
  idioma del cliente.
- **Refunds y cancelaciones** desde el Dashboard de Saleor.
- **APL pluggable**: `file` para dev, `redis` para producción
  (comparte el Redis del proyecto, prefijos `saleor:apl:*` /
  `saleor:cfg:*`).
- **Deploy 1-click**: `Dockerfile`, `render.yaml`, `railway.json`.
- **Submission al [Saleor App Store](https://apps.saleor.io/)** lista.

---

## 2. Archivos

| Ruta | Propósito |
| --- | --- |
| `server.js` | Express app, montaje de webhooks y UI |
| `lib/manifest.js` | Builder del manifest Saleor |
| `lib/jws-verify.js` | Verificación de firma `Saleor-Signature` (JWS RS256 con JWKS cacheado) |
| `lib/apl.js` | App Persistence Layer (file / redis) |
| `lib/config-store.js` | Configuración RENOVAX por-tenant |
| `lib/renovax.js` | Cliente HTTP de la API merchant de RENOVAX |
| `lib/saleor-client.js` | Cliente GraphQL minimal (`transactionEventReport`) |
| `lib/event-map.js` | RENOVAX `event_type` → Saleor `TransactionEventType` |
| `lib/locale.js` | `LanguageCodeEnum` → ISO 2 letras |
| `webhooks/*.js` | 6 handlers Saleor + 1 callback RENOVAX |
| `ui/configuration.{html,js}` | Panel del Dashboard (Bootstrap + `.rnx-brand`) |
| `tests/*.test.js` | `vitest` unitarios |
| `deploy/Dockerfile` | Imagen producción Node 20 alpine |
| `deploy/render.yaml` | Despliegue Render 1 click |
| `deploy/railway.json` | Despliegue Railway 1 click |

---

## 3. Requisitos

| Recurso | Detalle |
| --- | --- |
| Node.js | 18+ |
| HTTPS público | Obligatorio — Saleor y RENOVAX solo entregan webhooks a `https://` |
| Saleor | 3.13+ (Cloud o self-host) |
| Redis | Opcional pero recomendado en producción para multi-tenant |
| Cuenta RENOVAX | Activa en [payments.renovax.net](https://payments.renovax.net) |

---

## 4. Instalación

### Paso 1 — Despliega el servicio

**Opción A — Render** (1 click): conecta el repo y Render leerá
`deploy/render.yaml`.

**Opción B — Railway** (1 click): `railway up` con `deploy/railway.json`.

**Opción C — Docker**:

```bash
docker build -f deploy/Dockerfile -t renovax-saleor .
docker run -d -p 3000:3000 \
  -e APP_URL=https://renovax-saleor.tudominio.com \
  -e APL=redis -e REDIS_URL=redis://redis:6379/0 \
  renovax-saleor
```

**Opción D — Manual**:

```bash
cd docs/integrations/saleor
cp .env.example .env  # edita APP_URL
npm install
npm start
```

Pon el servicio detrás de un proxy con HTTPS (Caddy, Nginx, Cloudflare).

### Paso 2 — Instala la App en Saleor

En el Dashboard de Saleor:

1. **Apps → Install external app**.
2. Pega: `https://TU-APP.com/api/manifest`.
3. Acepta los permisos (`HANDLE_PAYMENTS`).
4. La App queda instalada.

### Paso 3 — Configura tus credenciales RENOVAX

1. En Saleor: **Apps → RENOVAX Payments → Open**.
2. Se abre el panel embebido. Pega:
   - **Bearer token** (de RENOVAX → Merchants → API Tokens).
   - **Webhook secret** (de la misma pantalla).
3. Click **Verify and save**. El servicio valida el token contra
   RENOVAX antes de guardarlo.

### Paso 4 — Registra el webhook en RENOVAX

En tu merchant de RENOVAX, configura:

```text
webhook_url: https://TU-APP.com/api/webhooks/renovax
```

Listo. RENOVAX Payments aparece ya como método de pago en el checkout.

---

## 5. Flujo de pago

1. Cliente llega al checkout y elige **RENOVAX Payments**.
2. Storefront llama `transactionInitialize` → Saleor envía
   `TRANSACTION_INITIALIZE_SESSION` a este servicio.
3. Creamos una invoice en RENOVAX y respondemos `CHARGE_REQUEST` con
   `pay_url` y `pspReference = invoice.id`.
4. Storefront redirige al cliente al `pay_url`.
5. Cliente paga (Crypto/Stripe/PayPal/etc.).
6. RENOVAX envía webhook firmado a `/api/webhooks/renovax`.
7. Verificamos HMAC, resolvemos el tenant y llamamos
   `transactionEventReport` en Saleor → orden a `Paid`.

| RENOVAX `event_type` | Saleor event |
| --- | --- |
| `invoice.paid` | `CHARGE_SUCCESS` |
| `invoice.overpaid` | `CHARGE_SUCCESS` + mensaje OVERPAID |
| `invoice.partial` | `CHARGE_FAILURE` (revisión manual) |
| `invoice.expired` | `CANCEL_SUCCESS` |
| `invoice.refunded` | `REFUND_SUCCESS` |

---

## 6. Multi-tenant

Cada `Saleor-Api-Url` tiene su propia configuración persistida en APL:

```
APL backend (file o redis):
  saleor:apl:https://store-a.saleor.cloud/graphql/   → { auth_token, ... }
  saleor:apl:https://store-b.saleor.cloud/graphql/   → { auth_token, ... }
  saleor:cfg:https://store-a.saleor.cloud/graphql/   → { renovaxBearer, ... }
  saleor:cfg:https://store-b.saleor.cloud/graphql/   → { renovaxBearer, ... }
```

El callback de RENOVAX trae `metadata.saleor_api_url` en la invoice,
que usamos para resolver el tenant correcto.

---

## 7. Firewall / WAF

Endpoints firmados:

| Header | Origen | Uso |
| --- | --- | --- |
| `Saleor-Signature` | Saleor | JWS RS256 sobre el body |
| `Saleor-Api-Url` | Saleor | Identifica el tenant |
| `Saleor-Event` | Saleor | Tipo de webhook |
| `X-Renovax-Signature` | RENOVAX | HMAC-SHA256 sobre el body |
| `X-Renovax-Event-Id` | RENOVAX | UUID idempotencia |
| `X-Renovax-Event-Type` | RENOVAX | Tipo de evento |

**Cualquier WAF que reescriba el body romperá las firmas.** Desactiva
buffering / normalización JSON / rate-limit / CAPTCHA en `/api/webhooks/*`.

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

Cubre:
- Mapeo `event_type` → Saleor.
- Mapeo de locale.
- Idempotencia y verificación HMAC del callback.
- Verificación de firma JWS de Saleor (casos negativos).
- Manifest builder.

---

## 9. Troubleshooting

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` Saleor | WAF reescribe el body, o JWKS no accesible desde el servidor |
| `401 invalid_signature` RENOVAX | `webhook secret` configurado en el panel no coincide con el de RENOVAX |
| `unknown_tenant` en logs | `metadata.saleor_api_url` falta en la invoice — revisa que la App haya sido la que la creó |
| `token_verification_failed` al guardar config | Bearer caducado o URL `renovaxApiBase` errónea |
| Gateway no aparece en checkout | Permiso `HANDLE_PAYMENTS` no concedido al instalar la App |

---

## 10. Publicar en el Saleor App Store

Una vez probada, sube la App al [Saleor App Store oficial](https://apps.saleor.io/):

1. Fork del repo público de RENOVAX.
2. Sube el manifest URL al formulario de submission.
3. Saleor revisa y publica — distribución gratis a toda la red.

---

## 11. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
