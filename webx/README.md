# RENOVAX Payments — Integración para WebX.One

Drop-in que añade **RENOVAX Payments** (crypto, PayPal, Stripe, PIX, Mercado
Pago, etc.) como método de recarga de saldo en cualquier instalación
**WebX.One** (alternativa a DHRU Fusion para negocios de unlocking GSM/IMEI).

> **Por qué este patrón**: WebX.One está cifrado con **ionCube** y no expone
> una Gateway Module API pública estilo Dhru o WHMCS. La forma estándar de
> añadir gateways custom a WebX (ya usada en producción por otros
> integradores) es desplegar páginas PHP standalone que **escriben
> directamente** en la BD `webx` para acreditar `users.balance`. Esta
> integración hace exactamente eso, optimizado para RENOVAX Payments:
> webhook firmado HMAC-SHA256, transacciones atómicas, idempotencia,
> branding RENOVAX y soporte multi-idioma (en, es, fr, pt, ru, ar).

---

## 1. Archivos incluidos

| Archivo | Propósito |
| --- | --- |
| `index.php` | Checkout RENOVAX-branded (usuario + monto). |
| `create.php` | Valida input, crea invoice RENOVAX, redirige al `pay_url`. |
| `webhook.php` | Receptor del webhook firmado HMAC-SHA256. |
| `status.php` | Endpoint JSON read-only para el polling AJAX de la página de éxito. |
| `cleanup.php` | Cron diario que expira invoices pendientes viejas. |
| `sql.sql` | `CREATE TABLE pagos_renovax` (auditoría + idempotencia). |
| `lib/config.example.php` | Plantilla de configuración (cópiala a `config.php`). |
| `lib/bootstrap.php` | Bootstrap común. |
| `lib/db.php` | PDO con prepared statements. |
| `lib/renovax.php` | Cliente HTTP de la API RENOVAX Payments. |
| `lib/i18n.php` | Diccionario en 6 idiomas (en, es, fr, pt, ru, ar). |
| `lib/csrf.php` | Tokens CSRF para el form. |
| `lib/telegram.php` | Notificación opcional al admin. |
| `lib/.htaccess` + `lib/index.php` | Bloquean acceso HTTP a la carpeta `lib/`. |
| `assets/icon.png` + `assets/style.css` | Isotipo RENOVAX + estilos minimalistas. |

---

## 2. Requisitos del servidor

| Requisito | Detalle |
| --- | --- |
| WebX.One | Cualquier versión con BD `webx` y tabla `users` (`id`, `username`, `email`, `balance`) |
| PHP | 7.4+ con extensiones `pdo_mysql`, `curl`, `hash`, `json` |
| MySQL/MariaDB | 5.7+ / 10.3+ con tabla `users` accesible |
| HTTPS | Obligatorio — RENOVAX solo entrega webhooks a URLs `https://` |
| Salida HTTPS | TCP 443 abierto hacia `payments.renovax.net` |
| Cuenta RENOVAX Payments | Merchant activo en [payments.renovax.net](https://payments.renovax.net) con al menos un método de pago configurado |

---

## 3. Instalación

### Paso 1 — Obtener credenciales RENOVAX Payments

En **Merchants → (tu merchant) → Edit → API Tokens** de RENOVAX Payments:

1. Crea un **Bearer Token** (se muestra una sola vez — cópialo).
2. Copia el **Webhook Secret**.

### Paso 2 — Crear la tabla de auditoría

Ejecuta `sql.sql` contra la BD `webx`:

```bash
mysql -u root -p webx < sql.sql
```

Crea la tabla `pagos_renovax` (no toca `users`).

### Paso 3 — Subir los archivos

Sube la carpeta `renovax/` a la raíz pública de tu WebX, p. ej.
`/var/www/html/renovax/`. Mantén la estructura de carpetas.

```bash
chmod 755 /var/www/html/renovax
chmod 644 /var/www/html/renovax/*.php /var/www/html/renovax/lib/*.php
```

### Paso 4 — Configurar `lib/config.php`

```bash
cp lib/config.example.php lib/config.php
chmod 640 lib/config.php
chown www-data:www-data lib/config.php   # ajusta al usuario de tu web server
```

Edita `lib/config.php` con:

- Credenciales MySQL del WebX (`db.user`, `db.pass`).
- Bearer Token + Webhook Secret de RENOVAX Payments.
- Moneda en la que cobras (`renovax.currency`, default `USD`).
- Rangos de monto (`min_amount` / `max_amount`).
- Opcionalmente Telegram (deja `enabled: false` si no lo usas).
- Nombre del sitio + URL pública del drop-in.

### Paso 5 — Registrar el webhook URL

En **Merchants → (tu merchant) → Edit** de RENOVAX Payments, configura:

```text
webhook_url: https://TU-DOMINIO-WEBX.com/renovax/webhook.php
```

### Paso 6 — Enlazar el checkout desde el panel WebX

Añade un enlace "Recargar saldo" en el panel de cliente que apunte a:

```text
https://TU-DOMINIO-WEBX.com/renovax/
```

(El idioma se detecta automáticamente del navegador del cliente; también
puedes forzar uno con `?lang=es`, `?lang=fr`, etc.)

### Paso 7 — Cron de housekeeping (recomendado)

```cron
0 3 * * * /usr/bin/php /var/www/html/renovax/cleanup.php >> /var/log/renovax-cleanup.log 2>&1
```

Marca como `expired` los invoices `pending` con más de 24 h (configurable
en `config.limits.expire_after_hours`).

Listo.

---

## 4. Flujo de pago

1. Cliente abre `/renovax/` → ve checkout: usuario + monto + botón.
2. Submit → `create.php`:
   - Verifica CSRF token.
   - Valida usuario (acepta email **o** username) + monto en rango.
   - Aplica rate limits: máx N pendientes por usuario, máx M creaciones por IP en 10 min.
   - Crea invoice RENOVAX con `client_remote_id = "webx-{user_id}-{ts}"`.
   - INSERT en `pagos_renovax` con `status='pending'`.
   - Redirige al `pay_url`.
3. Cliente paga en checkout RENOVAX (Crypto / Stripe / PayPal).
4. RENOVAX → POST `/renovax/webhook.php` con cabeceras
   `X-Renovax-Signature`, `X-Renovax-Event-Id`, `X-Renovax-Event-Type`.
5. `webhook.php`:
   - Verifica HMAC-SHA256 contra `webhook_secret`.
   - Cross-check: `invoice_id` debe existir en `pagos_renovax`.
   - Cross-check: `metadata.webx_user_id` debe coincidir con el guardado.
   - Idempotencia: rechaza re-entregas con el mismo `event_id`.
   - **Transacción atómica**: `UPDATE users SET balance = balance + X` + `UPDATE pagos_renovax`.
   - 200 OK rápido bajo `ignore_user_abort(true)`.
   - (Opcional) notifica por Telegram.
6. Cliente vuelve a `/renovax/?status=ok` y ve mensaje de éxito + polling
   AJAX a `status.php` que refresca cuando `pagos_renovax.status = paid`.

| `event_type` RENOVAX | Acción WebX |
| --- | --- |
| `invoice.paid` | `users.balance += amount_net_fiat`; `status='paid'` |
| `invoice.overpaid` | Igual + status `overpaid`; alerta Telegram |
| `invoice.partial` | Solo registro; no acredita; alerta Telegram (revisión manual) |
| `invoice.expired` | Marca `status='expired'`; no acredita |

---

## 5. Filtros para el Webhook (firewall / WAF)

`/renovax/webhook.php` recibe webhooks firmados con HMAC-SHA256. Si tu WAF,
proxy inverso o firewall modifica la petición, **todas las firmas fallarán
con `401 invalid_signature`**.

### 5.1 Permitir IPs de RENOVAX Payments

Solicita la lista de IPs de egreso actual en
[payments.renovax.net/support](https://payments.renovax.net/support) y
añádelas a la lista blanca para `POST /renovax/webhook.php`.

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
| Rate limiting | **Whitelist** las IPs de RENOVAX |
| Bloqueo geográfico | **Usar IPs**, no países |
| Límite de tamaño de body | 1 MB es suficiente |

### 5.4 Ejemplos de configuración

**Cloudflare** — Configuration Rule para `tudominio.com/renovax/webhook.php`:

- Security Level: **Essentially Off**
- Browser Integrity Check: **Off**
- Bot Fight Mode: **Off**
- Cache Level: **Bypass**

**Nginx**:

```nginx
location = /renovax/webhook.php {
    client_max_body_size 1m;
    proxy_request_buffering off;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/renovax/webhook.php;
}

# Bloquear acceso público a lib/
location ~ /renovax/lib/ {
    deny all;
    return 403;
}
```

**Apache** — el `.htaccess` incluido en `lib/` ya bloquea el acceso.
Si usas mod_security, exclúyelo solo para `webhook.php`:

```apache
<Files "webhook.php">
    SecRuleEngine Off
</Files>
```

---

## 6. Solución de problemas

| Síntoma | Causa probable |
| --- | --- |
| `401 invalid_signature` en logs | El WAF modifica el body o `webhook_secret` no coincide |
| El balance nunca se acredita | La URL del webhook no está registrada en RENOVAX o el endpoint no es público |
| `RENOVAX authentication failed` al crear invoice | `bearer_token` incorrecto o caducado |
| El cliente ve "Usuario o monto inválido" siempre | El email/username no existe en `webx.users` o el monto está fuera de rango |
| Polling AJAX nunca actualiza | El webhook no llegó (revisa logs) o `pagos_renovax.status` no se actualizó |
| `db_transaction_failed` en logs | Permisos MySQL: el usuario debe tener `UPDATE` sobre `webx.users` |
| Acceso 403 a `/renovax/lib/config.php` desde navegador | ✅ Correcto — el `.htaccess` está funcionando |

Activa logs detallados con `tail -f /var/log/apache2/error.log | grep renovax-payments`
(o el equivalente de tu PHP error log).

---

## 7. Soporte

[payments.renovax.net/support](https://payments.renovax.net/support)

---

## Disclaimer

Esta integración escribe directamente en la tabla `users` de la BD `webx`.
WebX.One **no documenta esta práctica** y su código está cifrado con
ionCube. Si actualizas WebX a una versión que cambie el esquema de
`users.balance`, esta integración debe revisarse. Probado contra la
estructura observada en instalaciones de producción.

## Fuera de alcance v1

- **Refunds automáticos**: la API RENOVAX soporta refunds; un botón admin
  protegido (`refund.php`) está planificado para v1.1.
- **Embed dentro del panel WebX**: requiere modificar templates WebX, que
  están cifrados. Por ahora link externo.
- **Detección automática de credenciales WebX**: imposible por ionCube
  (config cifrado). Configuración manual en `lib/config.php`.
