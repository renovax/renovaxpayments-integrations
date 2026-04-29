/**
 * Wix Payment Provider SPI — request verification and response signing.
 *
 * Wix signs every request to the SPI with a JWS (RS256) using a public key
 * pair you upload to the Wix Dev Center. This module verifies that JWS and
 * exposes the embedded "data" claim which contains the actual call body.
 *
 * Reference: https://dev.wix.com/api/rest/wix-payments/payments/payment-provider-spi
 */

const crypto = require('node:crypto');

function base64UrlDecode(str) {
  const pad = '='.repeat((4 - (str.length % 4)) % 4);
  return Buffer.from((str + pad).replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

/**
 * Verify a JWS signed by Wix and return the parsed payload.
 * @param {string} jws  - The full request body sent by Wix (a JWS compact serialization).
 * @param {string} publicKeyPem - The public key (PEM) configured for the SPI.
 * @returns {object|null} The decoded payload, or null if signature is invalid.
 */
function verifyWixJws(jws, publicKeyPem) {
  if (typeof jws !== 'string' || !publicKeyPem) return null;
  const parts = jws.split('.');
  if (parts.length !== 3) return null;

  const [headerB64, payloadB64, sigB64] = parts;
  const signedInput = `${headerB64}.${payloadB64}`;
  const signature   = base64UrlDecode(sigB64);

  const verifier = crypto.createVerify('RSA-SHA256');
  verifier.update(signedInput);
  verifier.end();

  const valid = verifier.verify(publicKeyPem, signature);
  if (!valid) return null;

  try {
    return JSON.parse(base64UrlDecode(payloadB64).toString('utf8'));
  } catch {
    return null;
  }
}

module.exports = { verifyWixJws };
