/**
 * PAYMENT_GATEWAY_INITIALIZE_SESSION
 *
 * Storefront calls this once before showing the gateway. We answer with
 * branding/UI hints. There is no sensitive logic here — it just signals
 * that the gateway is alive and ready for a transactionInitialize call.
 */

module.exports = async function paymentGatewayInitialize({ event, config }) {
  return {
    data: {
      gateway: 'renovax-payments',
      label:   'RENOVAX Payments',
      description: 'Pay with crypto, Stripe, PayPal, PIX, Mercado Pago and more.',
      acceptsAuthorize: false,
      acceptsCharge:    true,
      apiBase:  config?.renovaxApiBase || 'https://payments.renovax.net',
    },
  };
};
