import { describe, expect, it } from 'vitest';
import { NAV_ITEMS, NAV_SECTIONS } from './navigation';
import en from '@/shared/i18n/locales/en.json';
import ar from '@/shared/i18n/locales/ar.json';

function resolve(bundle: unknown, key: string): unknown {
  return key.split('.').reduce<unknown>((carry, part) => {
    if (carry && typeof carry === 'object' && part in carry) {
      return (carry as Record<string, unknown>)[part];
    }
    return undefined;
  }, bundle);
}

describe('navigation', () => {
  it('has unique paths', () => {
    const paths = NAV_ITEMS.map((item) => item.path);
    expect(new Set(paths).size).toBe(paths.length);
  });

  it('places every item in a known section', () => {
    for (const item of NAV_ITEMS) {
      expect(NAV_SECTIONS).toContain(item.section);
    }
  });

  it('is fully translated in English and Arabic', () => {
    for (const item of NAV_ITEMS) {
      expect(resolve(en, item.labelKey), `en:${item.labelKey}`).toEqual(expect.any(String));
      expect(resolve(ar, item.labelKey), `ar:${item.labelKey}`).toEqual(expect.any(String));
    }
    for (const section of NAV_SECTIONS) {
      expect(resolve(en, `nav.sections.${section}`)).toEqual(expect.any(String));
      expect(resolve(ar, `nav.sections.${section}`)).toEqual(expect.any(String));
    }
  });

  it('declares a delivery phase for every surface', () => {
    for (const item of NAV_ITEMS) {
      expect(item.phase).toMatch(/^Phase \d+$/);
    }
  });
});
