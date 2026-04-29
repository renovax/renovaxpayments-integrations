/**
 * TRANSACTION_PROCESS_SESSION
 *
 * Storefront polls this to learn the current invoice state without
 * waiting for the async webhook. We re-read the invoice from RENOVAX
 * and answer with the matching Saleor result.
 */

const { RenovaxClient } = require('../lib/renovax');

const STATUS_TO_RESULT = {
  paid:      'CHARGE_SUCCESS',
  overpaid:  'CHARGE_SUCCESS',
  partial:   'CHARGE_FAILURE',
  pending:   'CHARGE_REQUEST',
  expired:   'CHARGE_FAILURE',
  canceled:  'CHARGE_FAILURE',
  failed:    'CHARGE_FAILURE',
};

module.exports = async function transactionProcess({ event, config }) {
  const evt        = event?.event || event;
  const action     = evt.action || {};
  const transaction= evt.transaction || {};
  const invoiceId  = transaction.pspReference;
  const amount     = action.amount;

  if (!invoiceId)  return { result: 'CHARGE_FAILURE', amount: amount || 0, message: 'missing pspReference' };
  if (!config)     return { result: 'CHARGE_FAILURE', amount: amount || 0, message: 'RENOVAX not configured' };

  const renovax = new RenovaxClient({ apiBase: config.renovaxApiBase, token: config.renovaxBearerToken });

  try {
    const invoice = await renovax.getInvoice(invoiceId);
    const result  = STATUS_TO_RESULT[invoice.status] || 'CHARGE_REQUEST';
    return {
      result,
      amount:       invoice.amount_received_fiat ?? invoice.amount ?? amount,
      pspReference: invoice.id,
      message:      `RENOVAX status: ${invoice.status}`,
      externalUrl:  invoice.pay_url,
    };
  } catch (err) {
    return { result: 'CHARGE_FAILURE', amount: amount || 0, message: `RENOVAX error: ${err.message}` };
  }
};
