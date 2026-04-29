/**
 * Verify Saleor webhook signature.
 *
 * Saleor signs each webhook body with a JWS (RS256) using the source
 * Saleor's private key. The matching public key is published as JWKS
 * at  ${saleorApiUrl}/.well-known/jwks.json.
 *
 * We cache the JWKS per Saleor URL for 10 minutes.
 *
 * The signature header is `Saleor-Signature` and contains a detached
 * JWS in the form `header..signature` (the payload section is empty
 * because the actual payload is the raw HTTP body).
 */

const { createRemoteJWKSet, compactVerify } = require('jose');

const JWKS_CACHE = new Map();
const TTL_MS     = 10 * 60 * 1000;

function getJwks(saleorApiUrl) {
  const now = Date.now();
  const cached = JWKS_CACHE.get(saleorApiUrl);
  if (cached && (now - cached.t) < TTL_MS) return cached.jwks;

  const jwksUrl = new URL(saleorApiUrl);
  jwksUrl.pathname = '/.well-known/jwks.json';
  jwksUrl.search   = '';
  const jwks = createRemoteJWKSet(jwksUrl, { cooldownDuration: 60_000 });
  JWKS_CACHE.set(saleorApiUrl, { t: now, jwks });
  return jwks;
}

async function verifySaleorSignature({ saleorApiUrl, signatureHeader, rawBody }) {
  if (!signatureHeader) throw new Error('missing Saleor-Signature header');
  if (!saleorApiUrl)    throw new Error('missing Saleor-Api-Url header');

  const parts = String(signatureHeader).split('.');
  if (parts.length !== 3) throw new Error('malformed JWS in Saleor-Signature');

  const [protectedHeader, , signature] = parts;
  const payload = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(String(rawBody));
  const payloadB64 = payload.toString('base64url');
  const compact    = `${protectedHeader}.${payloadB64}.${signature}`;

  const jwks = getJwks(saleorApiUrl);
  await compactVerify(compact, jwks);
  return true;
}

module.exports = { verifySaleorSignature };
