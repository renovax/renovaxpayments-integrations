/**
 * TRANSACTION_CANCELATION_REQUESTED
 *
 * Cancel a pending invoice. If RENOVAX already settled it, the cancel
 * call will fail and we report CANCEL_FAILURE so the merchant can
 * decide to refund instead.
 */

const { RenovaxClient } = require('../lib/renovax');

module.exports = async function transactionCancel({ event, config }) {
  const evt         = event?.event || event;
  const transaction = evt.transaction || {};
  const invoiceId   = transaction.pspReference;

  if (!invoiceId) return { result: 'CANCEL_FAILURE', amount: 0, message: 'missing pspReference' };
  if (!config)    return { result: 'CANCEL_FAILURE', amount: 0, message: 'RENOVAX not configured' };

  const renovax = new RenovaxClient({ apiBase: config.renovaxApiBase, token: config.renovaxBearerToken });

  try {
    await renovax.cancelInvoice(invoiceId);
    return { result: 'CANCEL_SUCCESS', amount: 0, pspReference: invoiceId, message: 'Invoice canceled' };
  } catch (err) {
    return { result: 'CANCEL_FAILURE', amount: 0, message: `RENOVAX error: ${err.message}` };
  }
};
