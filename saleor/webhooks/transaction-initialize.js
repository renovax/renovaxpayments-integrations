/**
 * TRANSACTION_INITIALIZE_SESSION
 *
 * Saleor fires this when the storefront calls `transactionInitialize`.
 * We create a RENOVAX invoice and respond with CHARGE_REQUEST + pay_url.
 * The storefront then redirects the customer to the hosted checkout.
 */

const { RenovaxClient, RenovaxError } = require('../lib/renovax');
const { toRenovaxLocale }              = require('../lib/locale');

module.exports = async function transactionInitialize({ event, config, saleorApiUrl, ttlMinutes }) {
  const evt        = event?.event || event;
  const action     = evt.action || {};
  const source     = evt.sourceObject || {};
  const transaction= evt.transaction || {};

  const amount     = action.amount;
  const currency   = action.currency || source.totalPrice?.gross?.currency || source.channel?.currencyCode;
  const langRaw    = source.languageCodeEnum || source.languageCode;
  const locale     = toRenovaxLocale(langRaw);
  const email      = source.userEmail || source.email || '';
  const sourceId   = source.id;
  const sourceType = source.__typename || 'Checkout';
  const successUrl = evt.data?.successUrl || `${saleorApiUrl.replace(/\/graphql\/?$/, '')}/`;
  const cancelUrl  = evt.data?.cancelUrl  || successUrl;

  if (!amount || !currency) {
    return { result: 'CHARGE_FAILURE', amount: amount || 0, message: 'missing amount/currency' };
  }
  if (!config) {
    return { result: 'CHARGE_FAILURE', amount, message: 'RENOVAX not configured for this Saleor instance' };
  }

  const renovax = new RenovaxClient({
    apiBase: config.renovaxApiBase,
    token:   config.renovaxBearerToken,
  });

  try {
    const invoice = await renovax.createInvoice({
      amount:             String(amount),
      currency,
      client_remote_id:   transaction.id || `${sourceType}:${sourceId}`,
      success_url:        successUrl,
      cancel_url:         cancelUrl,
      expires_in_minutes: Math.max(1, Math.min(1440, parseInt(ttlMinutes, 10) || 30)),
      locale,
      metadata: {
        saleor_api_url:        saleorApiUrl,
        saleor_source_type:    sourceType,
        saleor_source_id:      sourceId,
        saleor_transaction_id: transaction.id,
        saleor_email:          email,
      },
    });

    return {
      result:       'CHARGE_REQUEST',
      amount,
      pspReference: invoice.id,
      message:      'Invoice created, awaiting payment',
      externalUrl:  invoice.pay_url,
      data: {
        payUrl:   invoice.pay_url,
        expires:  invoice.expires_at,
        provider: 'renovax-payments',
      },
    };
  } catch (err) {
    const code = (err instanceof RenovaxError) ? err.code : 'unknown';
    return {
      result:  'CHARGE_FAILURE',
      amount,
      message: `RENOVAX error: ${code}`,
      data:    { error: err.message },
    };
  }
};
