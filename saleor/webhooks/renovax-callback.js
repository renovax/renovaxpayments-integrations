/**
 * RENOVAX -> us. Async callback when an invoice changes state.
 *
 * Resolves the Saleor tenant from `metadata.saleor_api_url` set at
 * invoice creation time, then calls `transactionEventReport` so Saleor
 * moves the order to the right state.
 */

const crypto = require('node:crypto');
const { mapRenovaxEvent, pickAmount } = require('../lib/event-map');
const { SaleorClient } = require('../lib/saleor-client');

function verifyHmac(secret, rawBody, headerSig) {
  if (!secret || !headerSig) return false;
  const provided = String(headerSig).replace(/^sha256=/, '');
  const expected = crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  if (provided.length !== expected.length) return false;
  try { return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(provided)); }
  catch { return false; }
}

const seenEvents = new Map();
const EVENT_TTL  = 24 * 60 * 60 * 1000;
function isDuplicate(id) {
  if (!id) return false;
  const now = Date.now();
  for (const [k, t] of seenEvents) if (now - t > EVENT_TTL) seenEvents.delete(k);
  if (seenEvents.has(id)) return true;
  seenEvents.set(id, now);
  return false;
}

async function handleRenovaxWebhook(req, res, { apl, configStore }) {
  const rawBody    = req.body;
  const headerSig  = req.get('X-Renovax-Signature');
  const eventId    = req.get('X-Renovax-Event-Id');
  const eventType  = req.get('X-Renovax-Event-Type');

  let event;
  try { event = JSON.parse(rawBody.toString('utf8')); }
  catch { return res.status(400).json({ ok: false, error: 'invalid_json' }); }

  const saleorApiUrl = event?.metadata?.saleor_api_url;
  if (!saleorApiUrl) return res.status(400).json({ ok: false, error: 'missing_saleor_api_url' });

  const cfg = await configStore.get(saleorApiUrl);
  if (!cfg) return res.status(404).json({ ok: false, error: 'unknown_tenant' });

  if (!verifyHmac(cfg.renovaxWebhookSecret, rawBody, headerSig)) {
    return res.status(401).json({ ok: false, error: 'invalid_signature' });
  }

  if (isDuplicate(eventId)) return res.json({ ok: true, duplicate: true });

  const auth = await apl.get(saleorApiUrl);
  if (!auth) return res.status(404).json({ ok: false, error: 'unknown_app_install' });

  const mapped = mapRenovaxEvent(eventType || event.event_type);
  if (!mapped) return res.json({ ok: true, ignored: eventType });

  const transactionId = event?.metadata?.saleor_transaction_id;
  if (!transactionId) return res.status(400).json({ ok: false, error: 'missing_saleor_transaction_id' });

  const saleor = new SaleorClient({ saleorApiUrl, appToken: auth.token });
  try {
    await saleor.transactionEventReport({
      id:           transactionId,
      type:         mapped.type,
      amount:       String(pickAmount(event)),
      pspReference: event.id || event.invoice_id,
      time:         new Date().toISOString(),
      externalUrl:  event.pay_url || null,
      message:      mapped.message || `RENOVAX ${eventType}`,
    });
    return res.json({ ok: true });
  } catch (err) {
    console.error('[renovax->saleor] transactionEventReport failed', err);
    return res.status(500).json({ ok: false, error: 'saleor_report_failed', detail: err.message });
  }
}

module.exports = { handleRenovaxWebhook, verifyHmac, isDuplicate };
