/**
 * Translate a Saleor `LanguageCodeEnum` (e.g. ES_AR, PT_BR, AR_EG) to
 * one of the 6 ISO codes RENOVAX hosted checkout supports today:
 *   en, es, fr, pt, ru, ar.
 *
 * Fallback: 'en'.
 */

const SUPPORTED = new Set(['en', 'es', 'fr', 'pt', 'ru', 'ar']);

function toRenovaxLocale(saleorLanguageCode) {
  if (!saleorLanguageCode) return 'en';
  const head = String(saleorLanguageCode).toLowerCase().split(/[_-]/)[0];
  return SUPPORTED.has(head) ? head : 'en';
}

module.exports = { toRenovaxLocale, SUPPORTED };
