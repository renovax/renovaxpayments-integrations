import { describe, it, expect } from 'vitest';
import { mapRenovaxEvent, pickAmount } from '../lib/event-map.js';

describe('mapRenovaxEvent', () => {
  it('maps invoice.paid -> CHARGE_SUCCESS', () => {
    expect(mapRenovaxEvent('invoice.paid').type).toBe('CHARGE_SUCCESS');
  });
  it('maps invoice.overpaid -> CHARGE_SUCCESS with warning message', () => {
    const r = mapRenovaxEvent('invoice.overpaid');
    expect(r.type).toBe('CHARGE_SUCCESS');
    expect(r.message).toMatch(/OVERPAID/);
  });
  it('maps invoice.partial -> CHARGE_FAILURE so the order is not silently marked paid', () => {
    expect(mapRenovaxEvent('invoice.partial').type).toBe('CHARGE_FAILURE');
  });
  it('maps invoice.expired -> CANCEL_SUCCESS', () => {
    expect(mapRenovaxEvent('invoice.expired').type).toBe('CANCEL_SUCCESS');
  });
  it('maps invoice.refunded -> REFUND_SUCCESS', () => {
    expect(mapRenovaxEvent('invoice.refunded').type).toBe('REFUND_SUCCESS');
  });
  it('returns null for unknown events', () => {
    expect(mapRenovaxEvent('invoice.something_new')).toBeNull();
  });
});

describe('pickAmount', () => {
  it('prefers amount_received_fiat', () => {
    expect(pickAmount({ amount_received_fiat: 12.5, amount: 99 })).toBe(12.5);
  });
  it('falls back to amount when no fiat fields present', () => {
    expect(pickAmount({ amount: 7 })).toBe(7);
  });
  it('returns 0 for empty event', () => {
    expect(pickAmount({})).toBe(0);
  });
});
