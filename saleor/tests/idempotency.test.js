import { describe, it, expect } from 'vitest';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { isDuplicate, verifyHmac } = require('../webhooks/renovax-callback.js');

describe('isDuplicate', () => {
  it('returns false for null/undefined ids', () => {
    expect(isDuplicate(null)).toBe(false);
    expect(isDuplicate(undefined)).toBe(false);
  });
  it('returns true on second sighting of same id', () => {
    const id = 'evt_' + Math.random();
    expect(isDuplicate(id)).toBe(false);
    expect(isDuplicate(id)).toBe(true);
  });
});

describe('verifyHmac', () => {
  const secret = 'whsec_test';
  const body   = Buffer.from('{"event_type":"invoice.paid","id":"inv_1"}');
  const sig    = crypto.createHmac('sha256', secret).update(body).digest('hex');

  it('accepts valid signature', () => {
    expect(verifyHmac(secret, body, sig)).toBe(true);
  });
  it('accepts sha256= prefix', () => {
    expect(verifyHmac(secret, body, `sha256=${sig}`)).toBe(true);
  });
  it('rejects wrong signature', () => {
    expect(verifyHmac(secret, body, 'a'.repeat(64))).toBe(false);
  });
  it('rejects missing secret or header', () => {
    expect(verifyHmac('', body, sig)).toBe(false);
    expect(verifyHmac(secret, body, '')).toBe(false);
  });
});
