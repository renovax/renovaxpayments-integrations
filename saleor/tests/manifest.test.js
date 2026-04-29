import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { buildManifest } = require('../lib/manifest.js');

describe('buildManifest', () => {
  const m = buildManifest({ appUrl: 'https://app.example.com' });

  it('declares HANDLE_PAYMENTS permission', () => {
    expect(m.permissions).toContain('HANDLE_PAYMENTS');
  });
  it('uses literal product name "RENOVAX Payments"', () => {
    expect(m.name).toBe('RENOVAX Payments');
  });
  it('includes the 6 expected webhooks', () => {
    const events = m.webhooks.map(w => w.syncEvents[0]);
    expect(events).toEqual(expect.arrayContaining([
      'PAYMENT_GATEWAY_INITIALIZE_SESSION',
      'TRANSACTION_INITIALIZE_SESSION',
      'TRANSACTION_PROCESS_SESSION',
      'TRANSACTION_CHARGE_REQUESTED',
      'TRANSACTION_REFUND_REQUESTED',
      'TRANSACTION_CANCELATION_REQUESTED',
    ]));
  });
  it('points the configuration extension at /configuration', () => {
    expect(m.appUrl).toBe('https://app.example.com/configuration');
  });
  it('exposes a brand logo URL', () => {
    expect(m.brand.logo.default).toMatch(/rnx-logo\.png$/);
  });
});
