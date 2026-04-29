/**
 * RENOVAX Payments — Saleor App.
 *
 * Pattern: Saleor 3.13+ App with Transactions API.
 *
 * One deployment can serve N Saleor stores (multi-tenant): each tenant
 * installs the App from its Dashboard, then opens the configuration
 * panel to paste its RENOVAX bearer + webhook secret. Both pieces of
 * state live in the APL/config-store backend (file in dev, Redis in prod).
 *
 * Endpoints:
 *   GET  /api/manifest                                          (Saleor reads this)
 *   POST /api/register                                          (Saleor handshake)
 *   GET  /configuration                                          (Dashboard iframe UI)
 *   POST /api/configuration                                      (save tenant config)
 *   GET  /api/configuration                                      (read tenant config)
 *   POST /api/webhooks/saleor/payment-gateway-initialize-session
 *   POST /api/webhooks/saleor/transaction-initialize-session
 *   POST /api/webhooks/saleor/transaction-process-session
 *   POST /api/webhooks/saleor/transaction-charge-requested
 *   POST /api/webhooks/saleor/transaction-refund-requested
 *   POST /api/webhooks/saleor/transaction-cancelation-requested
 *   POST /api/webhooks/renovax                                   (RENOVAX -> us)
 *   GET  /healthz
 *   GET  /static/*                                               (logo etc.)
 */

require('dotenv').config();
const path    = require('node:path');
const express = require('express');

const { buildManifest }            = require('./lib/manifest');
const { makeAPL }                  = require('./lib/apl');
const { makeConfigStore }          = require('./lib/config-store');
const { verifySaleorSignature }    = require('./lib/jws-verify');
const { RenovaxClient }            = require('./lib/renovax');

const paymentGatewayInitialize  = require('./webhooks/payment-gateway-initialize');
const transactionInitialize     = require('./webhooks/transaction-initialize');
const transactionProcess        = require('./webhooks/transaction-process');
const transactionRefund         = require('./webhooks/transaction-refund');
const transactionCancel         = require('./webhooks/transaction-cancel');
const { handleRenovaxWebhook }  = require('./webhooks/renovax-callback');

const {
  APP_URL = 'http://localhost:3000',
  PORT    = 3000,
  INVOICE_TTL_MINUTES = '30',
} = process.env;

const apl         = makeAPL();
const configStore = makeConfigStore();

const app = express();

// All Saleor + RENOVAX webhooks need the raw body for signature checks.
app.use((req, res, next) => {
  if (req.path.startsWith('/api/webhooks') || req.path === '/api/register') {
    express.raw({ type: '*/*', limit: '2mb' })(req, res, next);
  } else {
    express.json({ limit: '1mb' })(req, res, next);
  }
});

app.use('/static', express.static(path.join(__dirname, 'static')));

app.get('/healthz', (_req, res) => res.json({ ok: true }));

app.get('/api/manifest', (_req, res) => {
  res.json(buildManifest({ appUrl: APP_URL }));
});

// Saleor handshake: it POSTs { auth_token } and we persist it for this saleorApiUrl.
app.post('/api/register', async (req, res) => {
  const saleorApiUrl  = req.get('Saleor-Api-Url');
  const saleorDomain  = req.get('Saleor-Domain') || (saleorApiUrl ? new URL(saleorApiUrl).host : null);
  let payload;
  try { payload = JSON.parse(req.body.toString('utf8')); }
  catch { return res.status(400).json({ success: false, error: { code: 'INVALID_JSON' } }); }

  if (!saleorApiUrl || !payload?.auth_token) {
    return res.status(400).json({ success: false, error: { code: 'MISSING_FIELDS' } });
  }
  await apl.set({
    saleorApiUrl,
    domain:    saleorDomain,
    token:     payload.auth_token,
    appId:     'net.renovax.payments.saleor',
    jwks:      null,
  });
  res.json({ success: true });
});

// ---------- Dashboard configuration UI ----------

app.get('/configuration', (_req, res) => {
  res.sendFile(path.join(__dirname, 'ui', 'configuration.html'));
});
app.get('/configuration.js', (_req, res) => {
  res.sendFile(path.join(__dirname, 'ui', 'configuration.js'));
});

app.get('/api/configuration', async (req, res) => {
  const saleorApiUrl = req.query.saleorApiUrl;
  if (!saleorApiUrl) return res.status(400).json({ ok: false, error: 'missing_saleor_api_url' });
  const cfg = await configStore.get(saleorApiUrl);
  if (!cfg) return res.json({ ok: true, configured: false });
  res.json({
    ok: true,
    configured: true,
    config: {
      renovaxApiBase: cfg.renovaxApiBase,
      hasBearer:      Boolean(cfg.renovaxBearerToken),
      hasSecret:      Boolean(cfg.renovaxWebhookSecret),
    },
  });
});

app.post('/api/configuration', async (req, res) => {
  let body;
  try { body = JSON.parse(req.body.toString('utf8')); }
  catch { return res.status(400).json({ ok: false, error: 'invalid_json' }); }
  const { saleorApiUrl, renovaxApiBase, renovaxBearerToken, renovaxWebhookSecret } = body;
  if (!saleorApiUrl)        return res.status(400).json({ ok: false, error: 'missing_saleor_api_url' });
  if (!renovaxBearerToken)  return res.status(400).json({ ok: false, error: 'missing_bearer' });
  if (!renovaxWebhookSecret)return res.status(400).json({ ok: false, error: 'missing_webhook_secret' });

  // Verify the bearer before saving.
  const probe = new RenovaxClient({ apiBase: renovaxApiBase, token: renovaxBearerToken });
  try { await probe.verifyToken(); }
  catch (err) { return res.status(400).json({ ok: false, error: 'token_verification_failed', detail: err.message }); }

  await configStore.set(saleorApiUrl, {
    renovaxApiBase: renovaxApiBase || 'https://payments.renovax.net',
    renovaxBearerToken,
    renovaxWebhookSecret,
    updatedAt: new Date().toISOString(),
  });
  res.json({ ok: true });
});

// ---------- Saleor webhook router ----------

function makeSaleorWebhook(handler) {
  return async (req, res) => {
    const saleorApiUrl   = req.get('Saleor-Api-Url');
    const signature      = req.get('Saleor-Signature');
    const rawBody        = req.body;

    try { await verifySaleorSignature({ saleorApiUrl, signatureHeader: signature, rawBody }); }
    catch (err) { return res.status(401).json({ result: 'CHARGE_FAILURE', amount: 0, message: `invalid_signature: ${err.message}` }); }

    let event;
    try { event = JSON.parse(rawBody.toString('utf8')); }
    catch { return res.status(400).json({ result: 'CHARGE_FAILURE', amount: 0, message: 'invalid_json' }); }

    const cfg = await configStore.get(saleorApiUrl);

    try {
      const out = await handler({
        event,
        config:       cfg,
        saleorApiUrl,
        ttlMinutes:   INVOICE_TTL_MINUTES,
      });
      res.json(out);
    } catch (err) {
      console.error('[saleor webhook] handler error', err);
      res.status(500).json({ result: 'CHARGE_FAILURE', amount: 0, message: err.message });
    }
  };
}

app.post('/api/webhooks/saleor/payment-gateway-initialize-session',
  makeSaleorWebhook(paymentGatewayInitialize));
app.post('/api/webhooks/saleor/transaction-initialize-session',
  makeSaleorWebhook(transactionInitialize));
app.post('/api/webhooks/saleor/transaction-process-session',
  makeSaleorWebhook(transactionProcess));
app.post('/api/webhooks/saleor/transaction-charge-requested',
  makeSaleorWebhook(transactionProcess));
app.post('/api/webhooks/saleor/transaction-refund-requested',
  makeSaleorWebhook(transactionRefund));
app.post('/api/webhooks/saleor/transaction-cancelation-requested',
  makeSaleorWebhook(transactionCancel));

// ---------- RENOVAX -> us ----------

app.post('/api/webhooks/renovax', (req, res) => handleRenovaxWebhook(req, res, { apl, configStore }));

if (require.main === module) {
  app.listen(Number(PORT), () => {
    console.log(`RENOVAX Payments Saleor App listening on :${PORT}`);
    console.log(`Manifest: ${APP_URL}/api/manifest`);
  });
}

module.exports = { app, apl, configStore };
