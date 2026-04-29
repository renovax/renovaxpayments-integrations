import { describe, it, expect } from 'vitest';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { verifySaleorSignature } = require('../lib/jws-verify.js');

describe('verifySaleorSignature', () => {
  it('rejects missing header', async () => {
    await expect(verifySaleorSignature({
      saleorApiUrl: 'https://demo.saleor.cloud/graphql/',
      signatureHeader: '',
      rawBody: Buffer.from('{}'),
    })).rejects.toThrow(/missing Saleor-Signature/);
  });
  it('rejects missing saleorApiUrl', async () => {
    await expect(verifySaleorSignature({
      saleorApiUrl: '',
      signatureHeader: 'a.b.c',
      rawBody: Buffer.from('{}'),
    })).rejects.toThrow(/missing Saleor-Api-Url/);
  });
  it('rejects malformed JWS', async () => {
    await expect(verifySaleorSignature({
      saleorApiUrl: 'https://demo.saleor.cloud/graphql/',
      signatureHeader: 'not-a-jws',
      rawBody: Buffer.from('{}'),
    })).rejects.toThrow(/malformed JWS/);
  });
});
