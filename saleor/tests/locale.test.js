import { describe, it, expect } from 'vitest';
import { toRenovaxLocale } from '../lib/locale.js';

describe('toRenovaxLocale', () => {
  it('passes through supported codes', () => {
    for (const c of ['en', 'es', 'fr', 'pt', 'ru', 'ar']) {
      expect(toRenovaxLocale(c)).toBe(c);
    }
  });
  it('strips region from Saleor LanguageCodeEnum', () => {
    expect(toRenovaxLocale('ES_AR')).toBe('es');
    expect(toRenovaxLocale('PT_BR')).toBe('pt');
    expect(toRenovaxLocale('AR_EG')).toBe('ar');
    expect(toRenovaxLocale('en-US')).toBe('en');
  });
  it('falls back to en for unsupported', () => {
    expect(toRenovaxLocale('JA')).toBe('en');
    expect(toRenovaxLocale(null)).toBe('en');
    expect(toRenovaxLocale('')).toBe('en');
  });
});
