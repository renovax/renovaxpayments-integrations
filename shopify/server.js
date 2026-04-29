/**
 * RENOVAX Payments — Shopify connector.
 *
 * Pattern: "off-site Manual payment method + automation app".
 *
 * Why this pattern:
 *   Shopify only allows custom payment gateways on Shopify Plus stores via the
 *   Payments Apps API. For everyone else, the merchant configures a "Manual
 *   payment method" called "RENOVAX Payments". When the customer chooses it
 *   at checkout the order is created with status `pending`. This service:
 *
 *   1. Receives Shopify webhook  orders/create  (HMAC verified).
 *   2. Creates a RENOVAX invoice and emails the customer the pay_url
 *      (and adds an order note with the link for the merchant to copy).
 *   3. Receives RENOVAX webhook   /webhooks/renovax  (HMAC verified).
 *   4. Calls Shopify Admin GraphQL  orderMarkAsPaid  to close the order.
 *
 * Required env vars: see .env.example.
 *
 * Endpoints:
 *   POST /webhooks/shopify/orders-create   (Shopify -> us)
 *   POST /webhooks/renovax                 (RENOVAX -> us)
 *   GET  /healthz
 */

require('dotenv').config();
const crypto  = require('node:crypto');
const express = require('express');
const { RenovaxClient } = require('./lib/renovax');
const { ShopifyClient } = require('./lib/shopify');

const {
  RENOVAX_API_BASE,
  RENOVAX_BEARER_TOKEN,
  RENOVAX_WEBHOOK_SECRET,
  SHOPIFY_SHOP,
  SHOPIFY_ADMIN_TOKEN,
  SHOPIFY_API_VERSION = '2024-10',
  SHOPIFY_WEBHOOK_SECRET,
  PORT = 3000,
  INVOICE_TTL_MINUTES = '15',
} = process.env;

const renovax = new RenovaxClient({ apiBase: RENOVAX_API_BASE, token: RENOVAX_BEARER_TOKEN });
const shopify = new ShopifyClient({ shop: SHOPIFY_SHOP, token: SHOPIFY_ADMIN_TOKEN, apiVersion: SHOPIFY_API_VERSION });

const app = express();
app.use(express.raw({ type: 'application/json' }));

const seenEvents = new Map();
const EVENT_TTL  = 24 * 60 * 60 * 1000;
const isDuplicate = (id) => {
  if (!id) return false;
  const now = Date.now();
  for (const [k, t] of seenEvents) if (now - t > EVENT_TTL) seenEvents.delete(k);
  if (seenEvents.has(id)) return true;
  seenEvents.set(id, now);
  return false;
};

function verifyShopifyHmac(rawBody, headerHmac) {
  if (!SHOPIFY_WEBHOOK_SECRET || !headerHmac) return false;
  const digest = crypto.createHmac('sha256', SHOPIFY_WEBHOOK_SECRET).update(rawBody).digest('base64');
  return crypto.timingSafeEqual(Buffer.from(digest), Buffer.from(headerHmac));
}

function verifyRenovaxHmac(rawBody, headerSig) {
  if (!RENOVAX_WEBHOOK_SECRET || !headerSig) return false;
  const provided = String(headerSig).replace(/^sha256=/, '');
  const expected = crypto.createHmac('sha256', RENOVAX_WEBHOOK_SECRET).update(rawBody).digest('hex');
  if (provided.length !== expected.length) return false;
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(provided));
}

app.get('/healthz', (_req, res) => res.json({ ok: true }));

app.post('/webhooks/shopify/orders-create', async (req, res) => {
  if (!verifyShopifyHmac(req.body, req.get('X-Shopify-Hmac-Sha256'))) {
    return res.status(401).json({ ok: false, error: 'invalid_signature' });
  }

  let order;
  try { order = JSON.parse(req.body.toString('utf8')); }
  catch { return res.status(400).json({ ok: false, error: 'invalid_json' }); }

  if ((order.gateway || '').toLowerCase() !== 'renovax payments' &&
      (order.payment_gateway_names || []).every(g => g.toLowerCase() !== 'renovax payments')) {
    return res.json({ ok: true, ignored: 'not_renovax_gateway' });
  }

  const orderGid = `gid://shopify/Order/${order.id}`;

  try {
    const invoice = await renovax.createInvoice({
      amount:             String(order.total_price),
      currency:           order.currency,
      client_remote_id:   String(order.id),
      success_url:        order.order_status_url || `https://${SHOPIFY_SHOP}/account/orders/${order.token}`,
      cancel_url:         order.order_status_url || `https://${SHOPIFY_SHOP}/account/orders/${order.token}`,
      expires_in_minutes: Math.max(1, Math.min(1440, parseInt(INVOICE_TTL_MINUTES, 10) || 15)),
      metadata: {
        shopify_order_id:   String(order.id),
        shopify_order_name: order.name,
        shopify_order_gid:  orderGid,
        shopify_email:      order.email || order.contact_email || '',
        shopify_shop:       SHOPIFY_SHOP,
      },
    });

    await shopify.addOrderNote(orderGid,
      `RENOVAX invoice ${invoice.id}\nPay URL: ${invoice.pay_url}`
    );

    return res.json({ ok: true, invoice_id: invoice.id, pay_url: invoice.pay_url });
  } catch (err) {
    console.error('[renovax] create_invoice failed', err);
    return res.status(500).json({ ok: false, error: err.code || 'internal' });
  }
});

app.post('/webhooks/renovax', async (req, res) => {
  if (!verifyRenovaxHmac(req.body, req.get('X-Renovax-Signature'))) {
    return res.status(401).json({ ok: false, error: 'invalid_signature' });
  }

  const eventId = req.get('X-Renovax-Event-Id');
  if (isDuplicate(eventId)) return res.json({ ok: true, duplicate: true });

  let event;
  try { event = JSON.parse(req.body.toString('utf8')); }
  catch { return res.status(400).json({ ok: false, error: 'invalid_json' }); }

  const eventType = req.get('X-Renovax-Event-Type') || event.event_type;
  const orderGid  = event.metadata?.shopify_order_gid
                  || (event.metadata?.shopify_order_id ? `gid://shopify/Order/${event.metadata.shopify_order_id}` : null);

  if (!orderGid) return res.json({ ok: false, error: 'missing_shopify_order' });

  try {
    switch (eventType) {
      case 'invoice.paid':
      case 'invoice.overpaid': {
        await shopify.markOrderAsPaid(orderGid);
        await shopify.addOrderNote(orderGid,
          `RENOVAX ${eventType === 'invoice.overpaid' ? 'OVERPAID' : 'paid'}: gross ${event.amount_received_fiat || event.amount_received} ${event.invoice_currency || ''}, net ${event.amount_net_fiat || event.amount_net}, fee ${event.fee || 0}, tx ${event.tx_hash || 'n/a'}`
        );
        return res.json({ ok: true });
      }
      case 'invoice.partial': {
        await shopify.addOrderNote(orderGid,
          `RENOVAX partial payment: ${event.amount_received_fiat || event.amount_received}. Manual review required.`
        );
        return res.json({ ok: true });
      }
      case 'invoice.expired': {
        await shopify.cancelOrder(orderGid).catch((e) => console.warn('cancelOrder warn', e.message));
        return res.json({ ok: true });
      }
      default:
        return res.json({ ok: true, ignored: eventType });
    }
  } catch (err) {
    console.error('[renovax] webhook processing failed', err);
    return res.status(500).json({ ok: false, error: 'internal' });
  }
});

app.listen(Number(PORT), () => {
  console.log(`RENOVAX Shopify connector listening on :${PORT}`);
});
