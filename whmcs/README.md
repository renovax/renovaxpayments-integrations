# RENOVAX Payments — Gateway para WHMCS

Integración nativa para que tu instalación **WHMCS** cobre con **RENOVAX
Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). Cuando el pago
se confirma, RENOVAX envía un webhook firmado y la invoice WHMCS se acredita
automáticamente vía `addInvoicePayment()`.

---

## 1. Archivos incluidos

| Archivo | Cópialo a (en tu servidor WHMCS) |
| --- | --- |
| `modules/gateways/renovaxpayments.php` | `/modules/gateways/renovaxpayments.php` |
| `modules/gateways/callback/renovaxpayments.php` | `/modules/gateways/callback/renovaxpayments.php` |
| `lang/overrides/english.php` | `/lang/overrides/english.php` (merge keys) |
| `lang/overrides/spanish.php` | `/lang/overrides/spanish.php` |
| `lang/overrides/french.php` | `/lang/overrides/french.php` |
| `lang/overrides/brazilian-portuguese.php` | `/lang/overrides/brazilian-portuguese.php` |
| `lang/overrides/russian.php` | `/lang/overrides/russian.php` |
| `lang/overrides/arabic.php` | `/lang/overrides/arabic.php` |

Sin cambios en la base de datos. Sin dependencias Composer externas.

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| WHMCS | 8.x+ (probado con 8.10) |
| PHP | 7.4+ con extensiones `curl` y `hash` |
| HTTPS | Obligatorio — RENOVAX solo entrega webhooks a URLs `https://` |
| Salida HTTPS | TCP 443 abierto hacia `payments.renovax.net` |
| Cuenta RENOVAX | Merchant activo en [payments.renovax.net](https://payments.renovax.net) con al menos un método de pago configurado |

---

## 3. Instalación

### Paso 1 — Obtener credenciales

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX Payments:

1. Crea un **Bearer Token** (se muestra una sola vez — cópialo).
2. Copia el **Webhook Secret**.

### Paso 2 — Subir los archivos

Vía FTP/SCP, copia los archivos respetando la estructura:

```bash
chmod 644 modules/gateways/renovaxpayments.php
chmod 644 modules/gateways/callback/renovaxpayments.php
chmod 644 lang/overrides/*.php
```

### Paso 3 — Activar el gateway en WHMCS

En el admin de WHMCS: **Setup → Apps & Integrations → Payment Gateways →
All Payment Gateways → "RENOVAX Payments" → Activate**.

| Campo | Valor |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | del Paso 1 (sin espacios) |
| **Webhook Secret** | del Paso 1 |
| **Invoice TTL (min)** | `15` |
| **Debug log** | Tick para depurar (opcional) |

### Paso 4 — Registrar la URL del webhook

En la configuración del merchant en RENOVAX, establece:

```text
https://TU-DOMINIO-WHMCS.com/modules/gateways/callback/renovaxpayments.php
```

### Paso 5 — Probar

Crea una invoice de prueba en WHMCS, click **Pay Now** → debe redirigir al
checkout RENOVAX. Tras pagar, la invoice queda en `Paid`.

Listo.

---

## 4. Flujo de pago

1. Cliente entra a la invoice WHMCS y pulsa **Pay Now**.
2. WHMCS llama a `renovaxpayments_link()` que crea una invoice RENOVAX
   (`POST /api/v1/merchant/invoices`) con `client_remote_id = invoiceid`.
3. Cliente es redirigido al `pay_url` y elige **Crypto / Stripe / PayPal**
   en el checkout hospedado de RENOVAX.
4. Cuando el pago se confirma, RENOVAX envía webhook firmado a
   `/modules/gateways/callback/renovaxpayments.php` con header
   `X-Renovax-Signature: sha256=<hmac>`.
5. El callback verifica la firma, deduplica con `checkCbTransID()` y llama
   a `addInvoicePayment()` para acreditar la invoice WHMCS.

| `event_type` RENOVAX | Acción WHMCS |
| --- | --- |
| `invoice.paid` | `addInvoicePayment(invoiceid, eventId, amount_received_fiat, fee, 'renovaxpayments')` |
| `invoice.overpaid` | Igual + nota destacada en el log |
| `invoice.partial` | Solo `logTransaction` (revisión manual) |
| `invoice.expired` | Solo `logTransaction` (WHMCS gestiona el timeout por su cuenta) |

**Refunds**: desde **Billing → Transactions → (transacción) → Refund**,
WHMCS llama a `renovaxpayments_refund()` que llama a
`POST /api/v1/merchant/invoices/{id}/refund` en RENOVAX.

---

## 5. Filtros para el Callback (firewall / WAF)

`/modules/gateways/callback/renovaxpayments.php` recibe webhooks firmados
con HMAC-SHA256. Si tu WAF, proxy inverso o firewall modifica la petición,
**todas las firmas fallarán con `401 invalid_signature`**.

### 5.1 Permitir IPs de RENOVAX Payments

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /modules/gateways/callback/renovaxpayments.php`.

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
| Bloqueo geográfico | **Usar IPs**, no países |
| Límite de tamaño de body | 1 MB es suficiente (los webhooks pesan < 4 KB) |

### 5.4 Ejemplos de configuración

**Cloudflare** — Configuration Rule para
`tudominio.com/modules/gateways/callback/renovaxpayments.php`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**
- Cloudflare APO / Auto Minify: **Off** (no debe tocar el body)

**Nginx**:

```nginx
location = /modules/gateways/callback/renovaxpayments.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    # No modifiques ni elimines headers X-Renovax-*
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

Muchos paneles cPanel ejecutan WHMCS detrás de **mod_security** y/o **CSF/LFD**.
Asegúrate de:
- Excluir `/modules/gateways/callback/renovaxpayments.php` del rule set OWASP CRS.
- Whitelist las IPs RENOVAX en CSF (`csf.allow`) para evitar bloqueos por
  número de hits.

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` en logs | El WAF está modificando el body o el `Webhook Secret` no coincide |
| La invoice WHMCS nunca pasa a `Paid` | La URL del webhook no está registrada en RENOVAX, o el callback no es público |
| `RENOVAX authentication failed` al pagar | `Bearer Token` incorrecto o caducado |
| Cliente ve `RENOVAX returned an incomplete response` | El merchant no tiene métodos de pago activos en RENOVAX |
| Refund declinado | El `transid` guardado no es un invoice UUID RENOVAX válido (revisa que `addInvoicePayment` recibió el `event_id` correcto) |

Activa **Debug log** en la configuración del gateway. Los eventos quedan en
**Utilities → Logs → Gateway Log** filtrados por `renovaxpayments`.

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
