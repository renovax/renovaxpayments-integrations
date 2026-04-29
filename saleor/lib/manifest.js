/**
 * Saleor App manifest builder.
 *
 * Saleor fetches this JSON to learn what permissions the App needs and
 * which webhooks it wants to receive. The merchant pastes the manifest
 * URL in: Dashboard -> Apps -> Install external app.
 */

function buildManifest({ appUrl, version = '1.0.0' }) {
  const base = appUrl.replace(/\/+$/, '');

  const subscription = (event, payload) => ({
    name:        event,
    asyncEvents: [],
    syncEvents:  [event],
    query:       payload,
    targetUrl:   `${base}/api/webhooks/saleor/${event.toLowerCase().replace(/_/g, '-')}`,
    isActive:    true,
  });

  return {
    id:          'net.renovax.payments.saleor',
    name:        'RENOVAX Payments',
    about:       'Accept crypto, Stripe, PayPal, PIX, Mercado Pago and more through RENOVAX Payments. Hosted checkout, automatic settlement, full refund support.',
    version,
    permissions: ['HANDLE_PAYMENTS'],
    appUrl:      `${base}/configuration`,
    configurationUrl: `${base}/configuration`,
    tokenTargetUrl:   `${base}/api/register`,
    dataPrivacyUrl:   'https://payments.renovax.net/legal/privacy',
    homepageUrl:      'https://payments.renovax.net',
    supportUrl:       'https://payments.renovax.net/support',
    brand: {
      logo: { default: `${base}/static/rnx-logo.png` },
    },
    extensions: [
      {
        label:  'RENOVAX Payments configuration',
        mount:  'NAVIGATION_CATALOG',
        target: 'POPUP',
        permissions: ['HANDLE_PAYMENTS'],
        url:    `${base}/configuration`,
      },
    ],
    webhooks: [
      subscription('PAYMENT_GATEWAY_INITIALIZE_SESSION',
        'subscription { event { ... on PaymentGatewayInitializeSession { sourceObject { __typename ... on Checkout { id } ... on Order { id } } amount data } } }'),
      subscription('TRANSACTION_INITIALIZE_SESSION',
        'subscription { event { ... on TransactionInitializeSession { transaction { id } sourceObject { __typename ... on Checkout { id channel { slug currencyCode } languageCode email totalPrice { gross { amount currency } } } ... on Order { id channel { slug currencyCode } languageCodeEnum userEmail total { gross { amount currency } } } } action { amount currency actionType } merchantReference data } } }'),
      subscription('TRANSACTION_PROCESS_SESSION',
        'subscription { event { ... on TransactionProcessSession { transaction { id pspReference } action { amount currency actionType } data } } }'),
      subscription('TRANSACTION_CHARGE_REQUESTED',
        'subscription { event { ... on TransactionChargeRequested { transaction { id pspReference } action { amount currency } } } }'),
      subscription('TRANSACTION_REFUND_REQUESTED',
        'subscription { event { ... on TransactionRefundRequested { transaction { id pspReference } action { amount currency } } } }'),
      subscription('TRANSACTION_CANCELATION_REQUESTED',
        'subscription { event { ... on TransactionCancelationRequested { transaction { id pspReference } } } }'),
    ],
  };
}

module.exports = { buildManifest };
