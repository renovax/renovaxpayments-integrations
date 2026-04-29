# RENOVAX Payments — Módulo para Magento 2

Módulo nativo para que tu tienda **Magento 2 / Adobe Commerce** cobre con
**RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.).
Cuando el pago se confirma, RENOVAX envía un webhook firmado y la orden
se factura (`invoice`) automáticamente.

---

## 1. Archivos incluidos

| Archivo | Propósito |
| --- | --- |
| `registration.php` + `composer.json` | Registro del módulo `Renovax_Payments` |
| `etc/module.xml` | Declaración + dependencias |
| `etc/config.xml` | Defaults del método de pago |
| `etc/payment.xml` | Registro como método de pago |
| `etc/adminhtml/system.xml` | Pantalla de configuración admin |
| `etc/frontend/routes.xml` + `di.xml` | Rutas frontend + ConfigProvider |
| `etc/db_schema.xml` | Columna `renovax_invoice_id` en `sales_order` |
| `Model/Payment.php` | Implementación `AbstractMethod` (con refunds) |
| `Model/Api/Client.php` | Cliente HTTP de la API merchant |
| `Model/Ui/ConfigProvider.php` | Expone config al checkout JS |
| `Controller/Redirect/Index.php` | Crea invoice + redirige al `pay_url` |
| `Controller/Webhook/Index.php` | Receptor del webhook firmado |
| `view/frontend/...` | Componente UI del método en el checkout |
| `i18n/*.csv` | Traducciones: en, es_ES, fr_FR, pt_BR, ru_RU, ar |

Sin dependencias Composer externas. Compatible con Magento 2.4.x / Adobe Commerce.

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| Magento | 2.4.x / Adobe Commerce 2.4.x |
| PHP | 7.4+ (8.1+ recomendado) |
| HTTPS | Obligatorio — RENOVAX solo entrega webhooks a URLs `https://` |
| Salida HTTPS | TCP 443 abierto hacia `payments.renovax.net` |
| Cuenta RENOVAX | Merchant activo en [payments.renovax.net](https://payments.renovax.net) |

---

## 3. Instalación

### Paso 1 — Obtener credenciales

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX Payments:

1. Crea un **Bearer Token** (se muestra una sola vez).
2. Copia el **Webhook Secret**.

### Paso 2 — Copiar el módulo

Copia la carpeta `Renovax/Payments/` al directorio `app/code/` de tu Magento:

```bash
cp -r Renovax/ <magento_root>/app/code/
```

### Paso 3 — Habilitar y compilar

Desde la raíz de Magento:

```bash
bin/magento module:enable Renovax_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Paso 4 — Configurar en el admin

En **Stores → Configuration → Sales → Payment Methods → RENOVAX Payments**:

| Campo | Valor |
| --- | --- |
| **Enabled** | Yes |
| **Title** | `RENOVAX Payments` |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | del Paso 1 (sin espacios) |
| **Webhook Secret** | del Paso 1 |
| **Invoice TTL (min)** | `15` |

### Paso 5 — Registrar la URL del webhook

La pantalla de configuración muestra la URL:

```text
https://TU-TIENDA.com/renovax/webhook
```

Pégala en la configuración del merchant en RENOVAX como `webhook_url`.
Listo.

---

## 4. Flujo de pago

1. Cliente elige **RENOVAX Payments** en el checkout.
2. Magento crea la orden en `pending_payment` y redirige a
   `/renovax/redirect`.
3. El controller llama a `POST {api}/api/v1/merchant/invoices` enviando el
   `increment_id` como `client_remote_id` (idempotente).
4. Cliente es redirigido al `pay_url` y elige Crypto / Stripe / PayPal en
   el checkout hospedado de RENOVAX.
5. Cuando el pago se confirma, RENOVAX envía webhook firmado a
   `/renovax/webhook`.
6. El módulo verifica HMAC, deduplica por `X-Renovax-Event-Id` (cache de
   Magento) y actualiza la orden.

| `event_type` RENOVAX | Acción Magento |
| --- | --- |
| `invoice.paid` | Crea `Order\Invoice` (CAPTURE_OFFLINE) + estado `processing` |
| `invoice.overpaid` | Igual + nota destacada |
| `invoice.partial` | Pasa a `holded` con nota |
| `invoice.expired` | `cancel()` (si no había invoice) |

Refunds: desde **Sales → Orders → View → Credit Memo** (totales o parciales).

---

## 5. Filtros para el Webhook (firewall / WAF)

`/renovax/webhook` recibe webhooks firmados con HMAC-SHA256. Si tu WAF o
firewall modifica la petición, **todas las firmas fallarán con `401
invalid_signature`**.

### 5.1 Permitir IPs de RENOVAX

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /renovax/webhook`.

### 5.2 Headers que deben pasar sin modificar

| Header | Propósito |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 del cuerpo raw |
| `X-Renovax-Event-Type` | Tipo de evento |
| `X-Renovax-Event-Id` | UUID único (idempotencia) |
| `Content-Type` | `application/json` |

### 5.3 Reglas WAF a desactivar **solo** para esta URL

| Regla | Acción |
| --- | --- |
| Buffering / reescritura del body | **Desactivar** |
| Validación / normalización JSON | **Desactivar** |
| Anti-bots / CAPTCHA | **Excluir** |
| Rate limiting | **Whitelist** RENOVAX |
| Bloqueo geográfico | **Usar IPs**, no países |

### 5.4 Ejemplo Nginx (configuración Magento estándar)

```nginx
location ^~ /renovax/webhook {
    client_max_body_size 1m;
    proxy_request_buffering off;
    try_files $uri $uri/ /index.php?$args;
    # No modifiques ni elimines headers X-Renovax-*
}
```

### 5.5 CSRF

El webhook implementa `CsrfAwareActionInterface` y devuelve
`validateForCsrf() => true` (saltándose CSRF, ya que valida HMAC).
**No** lo proteja con form-keys ni reCAPTCHA.

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` en logs | El WAF modifica el body o el `Webhook Secret` no coincide |
| El método no aparece en checkout | Falta `setup:di:compile` + `cache:flush` tras la copia |
| `RENOVAX authentication failed` al pagar | `Bearer Token` incorrecto o caducado |
| La orden no se factura tras pagar | Permisos del usuario admin para invoice o falta de `setup:upgrade` |
| `column renovax_invoice_id not found` | No se ejecutó `setup:upgrade` después de copiar el módulo |

Activa **Debug log** en la configuración para ver la traza en
`var/log/system.log`.

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
