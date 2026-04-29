# RENOVAX Payments — Plugin para WooCommerce

Plugin para que tu tienda **WooCommerce** cobre con **RENOVAX Payments**
(crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). Cuando el pago se confirma,
RENOVAX envía un webhook firmado y el pedido se marca como pagado
automáticamente.

---

## 1. Archivos incluidos

| Archivo | Ruta dentro del ZIP |
| --- | --- |
| `renovaxpayments.php` | Bootstrap (cabecera del plugin + hooks) |
| `includes/class-renovax-api-client.php` | Cliente HTTP de la API merchant |
| `includes/class-wc-gateway-renovax.php` | Gateway `WC_Payment_Gateway` |
| `includes/class-renovax-webhook.php` | Receptor del webhook firmado |
| `assets/icon.png` | Icono mostrado en el checkout |
| `languages/*.po` + `*.pot` | Traducciones: en, es, fr, pt_BR, ru, ar |
| `readme.txt` | Cabecera estándar de wp.org |

Sin cambios en la base de datos. Sin dependencias Composer.

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| WordPress | 6.0+ |
| WooCommerce | 8.0+ (compatible con HPOS — High-Performance Order Storage) |
| PHP | 7.4+ con `hash` y la HTTP API estándar de WP |
| HTTPS | Obligatorio — RENOVAX solo entrega webhooks a URLs `https://` |
| Salida HTTPS | TCP 443 abierto hacia `payments.renovax.net` |
| Cuenta RENOVAX | Merchant activo en [payments.renovax.net](https://payments.renovax.net) con al menos un método de pago configurado |

---

## 3. Instalación

### Paso 1 — Obtener credenciales

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX Payments:

1. Crea un **Bearer Token** (se muestra una sola vez — cópialo).
2. Copia el **Webhook Secret**.

### Paso 2 — Compilar las traducciones (.po → .mo)

WordPress solo carga `.mo` en runtime. Desde la carpeta `languages/`:

```bash
for f in renovaxpayments-*.po; do msgfmt "$f" -o "${f%.po}.mo"; done
```

O usa **Loco Translate** / **Poedit** desde el admin de WP.

### Paso 3 — Empaquetar y subir

Comprime la carpeta `renovaxpayments/` en un `.zip` y súbelo desde
**Plugins → Añadir nuevo → Subir plugin**, o copia la carpeta a
`wp-content/plugins/`.

Activa el plugin en **Plugins**.

### Paso 4 — Configurar el gateway

En **WooCommerce → Ajustes → Pagos → RENOVAX Payments**:

| Campo | Valor |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | del Paso 1 (sin espacios) |
| **Webhook Secret** | del Paso 1 |
| **Invoice TTL (min)** | `15` |
| **Habilitado** | ✓ |

### Paso 5 — Registrar la URL del webhook

La pantalla de ajustes muestra una **Webhook URL** del estilo:

```text
https://TU-TIENDA.com/wp-json/renovax/v1/webhook
```

Cópiala y pégala en la configuración del merchant en RENOVAX como
`webhook_url`. Listo.

---

## 4. Flujo de pago

1. El cliente elige **RENOVAX Payments** en el checkout.
2. El plugin llama a `POST {api}/api/v1/merchant/invoices` enviando el ID del
   pedido WC como `client_remote_id` (idempotente — un reintento devuelve la
   misma invoice, sin doble cobro).
3. El cliente es redirigido al `pay_url` y elige **Crypto / Stripe / PayPal**
   en el checkout hospedado de RENOVAX.
4. Cuando el pago se confirma, RENOVAX envía un webhook firmado a
   `/wp-json/renovax/v1/webhook` con el header
   `X-Renovax-Signature: sha256=<hmac>`.
5. El plugin verifica la firma, descarta entregas duplicadas por
   `X-Renovax-Event-Id` y actualiza el estado del pedido.

| `event_type` | Acción en WooCommerce |
| --- | --- |
| `invoice.paid` | `payment_complete()` + nota con bruto/neto/comisión |
| `invoice.overpaid` | `payment_complete()` + nota destacada de sobrepago |
| `invoice.partial` | Estado `on-hold` + nota de revisión manual |
| `invoice.expired` | Estado `cancelled` (solo si no estaba ya pagado) |

Refunds: desde el panel WC se llama a
`POST /api/v1/merchant/invoices/{id}/refund` (totales o parciales).

---

## 5. Filtros para el Webhook (firewall / WAF)

El endpoint `/wp-json/renovax/v1/webhook` recibe webhooks firmados con
HMAC-SHA256. Si tu WAF, proxy inverso o firewall modifica la petición,
**todas las firmas fallarán con `401 invalid_signature`**.

### 5.1 Permitir IPs de RENOVAX Payments

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /wp-json/renovax/v1/webhook`.

### 5.2 Headers que deben pasar sin modificar

| Header | Propósito |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 del cuerpo raw |
| `X-Renovax-Event-Type` | Tipo de evento (p. ej. `invoice.paid`) |
| `X-Renovax-Event-Id` | UUID único por entrega (idempotencia) |
| `Content-Type` | Debe llegar como `application/json` |

### 5.3 Reglas WAF a desactivar **solo** para esta URL

| Regla | Acción |
| --- | --- |
| Buffering / reescritura del body | **Desactivar** — el HMAC se calcula sobre los bytes exactos |
| Validación / normalización JSON | **Desactivar** |
| Anti-bots / CAPTCHA / JS challenge | **Excluir** este endpoint |
| Rate limiting | **Whitelist** de las IPs de RENOVAX |
| Bloqueo geográfico | **Usar IPs**, no países (RENOVAX puede egressar desde múltiples regiones) |
| Límite de tamaño de body | 1 MB es suficiente (los webhooks pesan < 4 KB) |
| Plugins de seguridad WP (Wordfence, iThemes, etc.) | Excluir la ruta `/wp-json/renovax/v1/webhook` de su firewall |

### 5.4 Ejemplos de configuración

**Cloudflare** — crea una Configuration Rule para
`tudominio.com/wp-json/renovax/v1/webhook`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx** — preserva los headers y desactiva el buffering:

```nginx
location = /wp-json/renovax/v1/webhook {
    client_max_body_size 1m;
    proxy_request_buffering off;
    # No modifiques ni elimines headers X-Renovax-*
    try_files $uri $uri/ /index.php?$args;
}
```

**Apache (.htaccess)** — no apliques `mod_security` ni filtros que reescriban
el body sobre esta ruta:

```apache
<LocationMatch "^/wp-json/renovax/v1/webhook">
    SecRuleEngine Off
</LocationMatch>
```

**Wordfence** — en **Wordfence → Firewall → All Firewall Options →
Whitelisted URLs**, añade `wp-json/renovax/v1/webhook`.

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` en logs | El WAF está modificando el body o el `Webhook Secret` no coincide |
| El pedido nunca pasa a `processing` | La URL del webhook no está registrada en RENOVAX, o el endpoint no es público |
| `RENOVAX Payments authentication failed` al pagar | `Bearer Token` incorrecto o caducado |
| El cliente ve `RENOVAX returned an incomplete response` | El merchant no tiene métodos de pago activos en RENOVAX |

Activa **Debug log** en los ajustes del plugin para ver toda la traza en
**WooCommerce → Estado → Registros** bajo el tag `renovax-*`.

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
