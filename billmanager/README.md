# RENOVAX Payments — Módulo para BILLmanager (ISPsystem)

Módulo nativo para que tu instalación **BILLmanager 6** cobre con **RENOVAX
Payments** (crypto, PayPal, Stripe, PIX, Mercado Pago, etc.). Cuando el pago
se confirma, RENOVAX envía un webhook firmado y BILLmanager actualiza el
estado del payment a `paid` automáticamente.

> **Nota sobre ISPmanager**: ISPmanager es el **panel de hosting** de
> ISPsystem (gestiona dominios, sitios, mail, etc.) — **no procesa pagos**.
> El billing oficial del ecosistema ISPsystem es **BILLmanager**, que se
> conecta a uno o varios ISPmanager. Por eso esta integración es para
> BILLmanager (donde están los clientes finales y las facturas).

---

## 1. Archivos incluidos

| Archivo | Cópialo a (en tu servidor BILLmanager) |
| --- | --- |
| `processing/pmrenovax.php` | `/usr/local/mgr5/processing/pmrenovax.php` |
| `etc/billmgr_mod_pmrenovax.xml` | `/usr/local/mgr5/etc/xml/billmgr_mod_pmrenovax.xml` |
| `callback/renovax_callback.php` | Web-served, p. ej. `/var/www/billmgr/callback/renovax_callback.php` |

> El XML de manifiesto incluye los 6 idiomas en línea (en, es, fr, pt, ru, ar).

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| BILLmanager | 6.x (probado con 6.108+) |
| PHP CLI | 7.4+ con `curl` y `hash` |
| Acceso root | Necesario para colocar archivos en `/usr/local/mgr5/` |
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

Como root, vía SCP:

```bash
scp processing/pmrenovax.php          root@billmgr:/usr/local/mgr5/processing/
scp etc/billmgr_mod_pmrenovax.xml     root@billmgr:/usr/local/mgr5/etc/xml/
scp callback/renovax_callback.php     root@billmgr:/var/www/billmgr/callback/
ssh root@billmgr 'chmod 755 /usr/local/mgr5/processing/pmrenovax.php && chmod 644 /usr/local/mgr5/etc/xml/billmgr_mod_pmrenovax.xml'
```

### Paso 3 — Configurar variables del callback

El callback HTTP necesita conocer el `webhook_secret`. Edítalo en el host
o expórtalo como variable de entorno del web server:

```bash
# Opción A: editar /var/www/billmgr/callback/renovax_callback.php
define('RENOVAX_WEBHOOK_SECRET', 'el-secret-del-paso-1');

# Opción B: variable de entorno (recomendado)
echo 'export RENOVAX_WEBHOOK_SECRET="el-secret-del-paso-1"' >> /etc/apache2/envvars   # Apache
# o en nginx + php-fpm pool:
echo 'env[RENOVAX_WEBHOOK_SECRET] = "el-secret-del-paso-1"' >> /etc/php/7.4/fpm/pool.d/www.conf
```

### Paso 4 — Reiniciar BILLmanager

```bash
/usr/local/mgr5/sbin/mgrctl -m billmgr mgrservice.restart
```

### Paso 5 — Activar el método de pago

En el admin de BILLmanager: **Provider → Payment methods → Add**.

| Campo | Valor |
| --- | --- |
| **Payment module** | RENOVAX Payments |
| **API Base URL** | `https://payments.renovax.net` |
| **Bearer Token** | del Paso 1 (sin espacios) |
| **Webhook Secret** | del Paso 1 |
| **Invoice TTL (min)** | `15` |

### Paso 6 — Registrar la URL del webhook

En la configuración del merchant en RENOVAX, establece:

```text
https://TU-BILLMANAGER.com/callback/renovax_callback.php
```

Listo.

---

## 4. Flujo de pago

1. Cliente entra a una factura BILLmanager y elige **RENOVAX Payments**.
2. BILLmanager invoca `pmrenovax.php --command PreparePayment`.
3. El script crea una invoice RENOVAX (`POST /api/v1/merchant/invoices`)
   con `client_remote_id = billmgr_payment_id` y devuelve `pay_url`.
4. BILLmanager redirige al cliente al `pay_url`.
5. Cliente paga en el checkout RENOVAX (Crypto/Stripe/PayPal).
6. RENOVAX envía webhook firmado a `/callback/renovax_callback.php`.
7. El callback verifica HMAC, deduplica por `X-Renovax-Event-Id`, y
   reenvía el evento a `pmrenovax.php --command PayCallback`.
8. `PayCallback` ejecuta `mgrctl payment.edit elid=… status=paid`.

| `event_type` RENOVAX | Estado BILLmanager |
| --- | --- |
| `invoice.paid` | `status=paid` |
| `invoice.overpaid` | `status=paid` (revisar en logs) |
| `invoice.partial` | `status=inpay` |
| `invoice.expired` | `status=cancelled` |

**Refunds**: el script declara `allow_partial_refund=true`. Cuando el admin
emite un reembolso desde BILLmanager, llamará a la API de RENOVAX en una
versión futura (v1.1 — ver `Fuera de alcance`).

---

## 5. Filtros para el Callback (firewall / WAF)

`/callback/renovax_callback.php` recibe webhooks firmados con HMAC-SHA256.
Si tu WAF, proxy inverso o firewall modifica la petición, **todas las
firmas fallarán con `401 invalid_signature`**.

### 5.1 Permitir IPs de RENOVAX Payments

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /callback/renovax_callback.php`.

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

### 5.4 Ejemplo Nginx

```nginx
location = /callback/renovax_callback.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/callback/renovax_callback.php;
}
```

### 5.5 ISPsystem firewall (si está activo)

Si usas el firewall integrado de ISPmanager/BILLmanager o **CSF/LFD** en el
mismo host, asegúrate de incluir las IPs de RENOVAX en el listado de
permitidas (`csf.allow`) y excluir la URL del callback de cualquier
challenge anti-bot.

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` en logs | El WAF modifica el body o `RENOVAX_WEBHOOK_SECRET` no coincide |
| `processing_script_missing` | Ruta incorrecta en `RENOVAX_PMRENOVAX_PATH` o permisos del archivo |
| El payment no pasa a `paid` tras pagar | `mgrctl` no es ejecutable por el usuario web, o `billmgr_payment_id` ausente en metadata |
| `create_invoice_failed status=401` | Bearer Token incorrecto o caducado |
| `create_invoice_failed status=422` | Currency no soportada por el merchant — usa una de las default del merchant |
| `spawn_failed` en el callback | El usuario del web server no puede invocar `php` CLI o `proc_open` está deshabilitado en `php.ini` |

Activa logs detallados:

```bash
tail -f /usr/local/mgr5/var/billmgr.log | grep renovax
tail -f /var/log/php_errors.log | grep renovax
```

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)

---

## Fuera de alcance v1

- Refunds automáticos vía botón en BILLmanager (requiere implementar
  `Refund` action en `pmrenovax.php` — está estructurado para añadirlo).
- Soporte de monedas adicionales (la lista en `Config()` cubre las más
  comunes; añade más si tu merchant las acepta).
- Distribución vía ISPsystem App Marketplace (requiere review oficial).
