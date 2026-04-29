/**
 * Map a RENOVAX webhook `event_type` to a Saleor `TransactionEventTypeEnum`.
 *
 * Saleor's transaction model has these terminal event types we use:
 *   CHARGE_SUCCESS, CHARGE_FAILURE, CHARGE_REQUEST,
 *   REFUND_SUCCESS, REFUND_FAILURE, REFUND_REQUEST,
 *   CANCEL_SUCCESS, CANCEL_FAILURE, CANCEL_REQUEST,
 *   AUTHORIZATION_SUCCESS, AUTHORIZATION_FAILURE, AUTHORIZATION_REQUEST.
 *
 * We deliberately map `invoice.partial` to CHARGE_FAILURE so the order
 * does not silently move to PAID; the merchant must reconcile manually.
 */

const RENOVAX_TO_SALEOR = {
  'invoice.paid':     { type: 'CHARGE_SUCCESS' },
  'invoice.overpaid': { type: 'CHARGE_SUCCESS', message: 'OVERPAID — verify before fulfillment' },
  'invoice.partial':  { type: 'CHARGE_FAILURE', message: 'PARTIAL payment received — manual reconciliation required' },
  'invoice.expired':  { type: 'CANCEL_SUCCESS', message: 'Invoice expired without payment' },
  'invoice.canceled': { type: 'CANCEL_SUCCESS', message: 'Invoice canceled' },
  'invoice.refunded': { type: 'REFUND_SUCCESS' },
  'invoice.refund_failed': { type: 'REFUND_FAILURE' },
};

function mapRenovaxEvent(eventType) {
  return RENOVAX_TO_SALEOR[eventType] || null;
}

function pickAmount(event) {
  return event.amount_received_fiat
      ?? event.amount_net_fiat
      ?? event.amount_received
      ?? event.amount
      ?? 0;
}

module.exports = { mapRenovaxEvent, pickAmount, RENOVAX_TO_SALEOR };
