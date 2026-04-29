/**
 * TRANSACTION_REFUND_REQUESTED
 *
 * The merchant clicked Refund in Saleor Dashboard. Forward to RENOVAX.
 */

const { RenovaxClient } = require('../lib/renovax');

module.exports = async function transactionRefund({ event, config }) {
  const evt         = event?.event || event;
  const action      = evt.action || {};
  const transaction = evt.transaction || {};
  const invoiceId   = transaction.pspReference;
  const amount      = action.amount;

  if (!invoiceId) return { result: 'REFUND_FAILURE', amount: amount || 0, message: 'missing pspReference' };
  if (!config)    return { result: 'REFUND_FAILURE', amount: amount || 0, message: 'RENOVAX not configured' };

  const renovax = new RenovaxClient({ apiBase: config.renovaxApiBase, token: config.renovaxBearerToken });

  try {
    const refund = await renovax.refundInvoice(invoiceId, { amount, reason: 'Saleor merchant refund' });
    return {
      result:       'REFUND_SUCCESS',
      amount,
      pspReference: refund.id || invoiceId,
      message:      'Refund accepted by RENOVAX',
    };
  } catch (err) {
    return { result: 'REFUND_FAILURE', amount: amount || 0, message: `RENOVAX error: ${err.message}` };
  }
};
