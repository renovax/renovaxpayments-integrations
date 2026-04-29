# RENOVAX Payments — Módulo para PrestaShop

Integración para que tu tienda **PrestaShop** cobre con **RENOVAX Payments**
(crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). Cuando el pago se confirma,
RENOVAX envía un webhook firmado y el pedido se crea (o se acredita)
automáticamente.

Compatible con **PrestaShop 1.7.x, 8.x y 9.x** (PHP 7.2 → 8.3).

---

## 1. Archivos incluidos

```
renovaxpayments/
├── renovaxpayments.php
├── config.xml
├── logo.png
├── classes/
│   ├── RenovaxApiClient.php
│   └── RenovaxLogger.php
├── controllers/front/
│   ├── validation.php
│   ├── webhook.php
│   ├── return.php
│   └── cancel.php
├── sql/
│   ├── install.php
│   └── uninstall.php
├── translations/
│   ├── en.xlf  es.xlf  fr.xlf  pt.xlf  ru.xlf  ar.xlf
└── views/templates/
    ├── front/return.tpl
    └── hook/payment_return.tpl
```

Sube la carpeta `renovaxpayments/` a `modules/` de tu tienda. La instalación
crea la tabla `ps_renovax_events` (idempotencia de webhooks).

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| PrestaShop | 1.7.x, 8.x o 9.x |
| PHP | 7.2+ con extensiones `curl`, `hash` y `json` |
| HTTPS | Obligatorio — RENOVAX solo entrega webhooks a URLs `https://` |
| Salida HTTPS | TCP 443 abierto hacia `payments.renovax.net` |
| MySQL/MariaDB | InnoDB (para el `INSERT IGNORE` por `event_id`) |
| Cuenta RENOVAX | Merchant activo en [payments.renovax.net](https://payments.renovax.net) con al menos un método de pago configurado |

---

## 3. Instalación

### Paso 1 — Obtener credenciales

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX Payments:

1. Crea un **Bearer Token** (se muestra una sola vez — cópialo).
2. Copia el **Webhook Secret**.

### Paso 2 — Subir el módulo

Copia la carpeta `renovaxpayments/` dentro de `modules/` de tu tienda (vía FTP,
SSH o ZIP desde el back office).

```bash
chmod -R 644 modules/renovaxpayments
find modules/renovaxpayments -type d -exec chmod 755 {} \;
```

### Paso 3 — Instalar desde el back office

**Modules → Module Manager → buscar "RENOVAX" → Install.**

Acepta el aviso de desinstalación (puedes desinstalar después). Tras instalar:

1. Click en **Configure**.
2. Pega los valores del Paso 1:

| Campo | Valor |
| --- | --- |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | del Paso 1 (sin espacios) |
| **Webhook Secret** | del Paso 1 |
| **Invoice TTL (min)** | `15` |
| **Debug log** | `Off` (actívalo solo para depurar) |

3. Copia el valor del campo **Webhook URL** que aparece en la configuración —
   es de la forma:

```text
https://TU-TIENDA/index.php?fc=module&module=renovaxpayments&controller=webhook
```

### Paso 4 — Registrar la URL del webhook

En la configuración del merchant en RENOVAX, pega esa URL en el campo
`webhook_url`. Listo.

### Paso 5 — Activar el método de pago

**Payment → Preferences** (PS 1.7) o **Payment → Payment Methods** (PS 8/9):
asegúrate de que **RENOVAX Payments** está habilitado para las monedas y
grupos de clientes deseados.

---

## 4. Filtros para el Webhook (firewall / WAF)

`controllers/front/webhook.php` recibe webhooks firmados con HMAC-SHA256. Si
tu WAF, proxy inverso o firewall modifica la petición, **todas las firmas
fallarán con `401 invalid_signature`**.

### 4.1 Permitir IPs de RENOVAX Payments

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /index.php?fc=module&module=renovaxpayments&controller=webhook`.

### 4.2 Headers que deben pasar sin modificar

| Header | Propósito |
| --- | --- |
| `X-Renovax-Signature` | HMAC-SHA256 del cuerpo raw |
| `X-Renovax-Event-Type` | Tipo de evento (p. ej. `invoice.paid`) |
| `X-Renovax-Event-Id` | UUID único por entrega (idempotencia) |
| `Content-Type` | Debe llegar como `application/json` |

### 4.3 Reglas WAF a desactivar **solo** para esta URL

| Regla | Acción |
| --- | --- |
| Buffering / reescritura del body | **Desactivar** — el HMAC se calcula sobre los bytes exactos |
| Validación / normalización JSON | **Desactivar** |
| Anti-bots / CAPTCHA / JS challenge | **Excluir** este endpoint |
| Rate limiting | **Whitelist** de las IPs de RENOVAX |
| Bloqueo geográfico | **Usar IPs**, no países |
| Límite de tamaño de body | 1 MB es suficiente |

### 4.4 Ejemplos de configuración

**Cloudflare** — Configuration Rule para la URL del webhook:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx** — preserva headers y desactiva el buffering del body:

```nginx
location ~ ^/index\.php$ {
    if ($arg_module = "renovaxpayments") {
        client_max_body_size 1m;
        proxy_request_buffering off;
    }
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
}
```

**Apache (.htaccess)** — desactiva mod_security para los webhooks de RENOVAX:

```apache
<If "%{QUERY_STRING} =~ /module=renovaxpayments&controller=webhook/">
    SecRuleEngine Off
</If>
```

---

## 5. Cómo funciona

```text
Customer ── checkout ──▶ validation.php ──▶ POST /api/v1/merchant/invoices ──▶ RENOVAX
                                                       │
                                                  pay_url
                                                       ▼
                                                Hosted checkout
                                                (crypto/Stripe/PayPal)
                                                       │
                                                  webhook
                                                       ▼
RENOVAX ──▶ POST /…&controller=webhook ──▶ HMAC OK ──▶ flush 200 ──▶ DB
                                                                       │
                                                                 validateOrder
                                                                  → PS_OS_PAYMENT
```

- La **order de PrestaShop se crea solo cuando llega `invoice.paid` o
  `invoice.overpaid`**. Esto evita pedidos huérfanos sin pagar.
- El `id` del invoice de RENOVAX se guarda en `OrderPayment.transaction_id`,
  por lo que puedes localizar el pago desde el back office.
- La idempotencia se aplica por `X-Renovax-Event-Id` en la tabla
  `ps_renovax_events`. Reentregas devuelven 200 sin efectos secundarios.
- El patrón `flush_and_close` envía el 200 a RENOVAX **antes** de escribir en
  la base de datos, por lo que una desconexión del lado remoto no aborta
  la creación del pedido.

### Estados mapeados

| Evento RENOVAX | Acción en PrestaShop |
| --- | --- |
| `invoice.paid` | crea la order en `PS_OS_PAYMENT` (o cambia el estado si ya existe) |
| `invoice.overpaid` | igual + nota privada "OVERPAID gross/net/fee" |
| `invoice.partial` | estado `RENOVAX — Pago parcial` (creado en la instalación) |
| `invoice.expired` | si la order existe sin pagar → `PS_OS_CANCELED` |

---

## 6. Reembolsos

Cuando creas un **Standard Refund** o **Partial Refund** desde el back office,
el módulo llama a `POST /api/v1/merchant/invoices/{id}/refund` con el monto
del slip y el motivo (campo *cancelNote*).

El resultado se registra como nota privada en el pedido. Si la API rechaza el
reembolso (por ejemplo: invoice no apto), la nota lo refleja y debes resolverlo
desde RENOVAX directamente.

---

## 7. Troubleshooting

| Síntoma | Causa probable | Solución |
| --- | --- | --- |
| `401 invalid_signature` en los logs | WAF reescribe el body, o el secret está mal copiado | Sigue la sección 4. Reemite el secret en RENOVAX si dudas. |
| `webhook_secret_not_configured` | Falta el secret en la config del módulo | Pega el secret en *Configure*. |
| El pago se completa pero el pedido no aparece | El webhook no llegó (firewall) o `ps_cart_id` se perdió | Mira el log `[renovax]`; verifica conectividad en `support`. |
| `invoice mismatch order=… stored=… incoming=…` | Webhook obsoleto/duplicado de otra invoice | Ignorable si el pedido ya está pagado; se rechaza por seguridad. |
| `cart load failed: id=…` | El carrito fue eliminado entre la creación del invoice y el webhook | Pide al cliente reintentar; el invoice expirará por TTL. |
| El módulo no aparece en checkout | Bearer Token o Webhook Secret vacíos | Configúralos. El módulo se auto-oculta si faltan. |
| `Settings updated.` pero sigue sin funcionar | Cache de PrestaShop | Back office → *Advanced Parameters → Performance → Clear cache*. |

Para activar logging detallado: **Configure → Debug log: Enabled**. Los eventos
se ven en *Advanced Parameters → Logs* filtrando por `RenovaxPayments`.

---

## 8. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)
