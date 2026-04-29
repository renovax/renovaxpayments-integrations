# RENOVAX Payments — Gateway para DHRU Fusion

Integración para que tu panel **DHRU Fusion** cobre con **RENOVAX Payments**
(crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). Cuando el pago se confirma,
RENOVAX envía un webhook firmado y el invoice se acredita automáticamente.

---

## 1. Archivos incluidos

| Archivo | Cópialo a (en tu servidor DHRU) |
| --- | --- |
| `modules/gateways/renovaxpayments.php` | `/modules/gateways/renovaxpayments.php` |
| `renovaxpaymentscallback.php` | `/renovaxpaymentscallback.php` (raíz pública) |

Solo **2 archivos**. No se requieren cambios en la base de datos.

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| DHRU Fusion | Cualquier versión con acceso a `modules/gateways/` |
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

### Paso 2 — Registrar la URL del webhook

En la configuración del merchant en RENOVAX, establece:

```text
https://TU-DOMINIO-DHRU.com/renovaxpaymentscallback.php
```

### Paso 3 — Subir los archivos

```bash
chmod 644 modules/gateways/renovaxpayments.php
chmod 644 renovaxpaymentscallback.php
```

### Paso 4 — Activar el gateway en DHRU

En el admin de DHRU: **Configuration → Payment Gateways → RENOVAX Payments → Edit**.

| Campo | Valor |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | del Paso 1 (sin espacios) |
| **Webhook Secret** | del Paso 1 |
| **Invoice TTL (min)** | `15` |
| **Status** | **Active** |

Listo.

---

## 4. Filtros para el Callback (firewall / WAF)

`renovaxpaymentscallback.php` recibe webhooks firmados con HMAC-SHA256. Si tu
WAF, proxy inverso o firewall modifica la petición, **todas las firmas fallarán
con `401 invalid_signature`**.

### 4.1 Permitir IPs de RENOVAX Payments

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /renovaxpaymentscallback.php`.

### 4.2 Headers que deben pasar sin modificar

| Header | Propósito |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 del cuerpo raw |
| `X-Renovax-Event-Type` | Tipo de evento (p. ej. `invoice.paid`) |
| `X-Renovax-Event-Id` | UUID único por entrega |
| `Content-Type` | Debe llegar como `application/json` |

### 4.3 Reglas WAF a desactivar **solo** para esta URL

| Regla | Acción |
| --- | --- |
| Buffering / reescritura del body | **Desactivar** — el HMAC se calcula sobre los bytes exactos |
| Validación / normalización JSON | **Desactivar** |
| Anti-bots / CAPTCHA / JS challenge | **Excluir** este endpoint |
| Rate limiting | **Whitelist** de las IPs de RENOVAX |
| Bloqueo geográfico | **Usar IPs**, no países (RENOVAX puede egressar desde múltiples regiones) |
| Límite de tamaño de body | 1 MB es suficiente (los webhooks pesan < 4 KB) |

### 4.4 Ejemplos de configuración

**Cloudflare** — crea un Page Rule o Configuration Rule para
`tudominio.com/renovaxpaymentscallback.php`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx** — preserva los headers y desactiva el buffering:

```nginx
location = /renovaxpaymentscallback.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    # No modifiques ni elimines headers X-Renovax-*
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/renovaxpaymentscallback.php;
}
```

**Apache (.htaccess)** — no apliques `mod_security` ni filtros que reescriban
el body sobre este archivo:

```apache
<Files "renovaxpaymentscallback.php">
    SecRuleEngine Off
    Header unset X-Frame-Options
</Files>
```

---

## 5. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
