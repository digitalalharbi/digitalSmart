import { describe, expect, it } from 'vitest';
import tokens from '@/styles/tokens.css?raw';
import {
  PLATFORM_ORDER,
  platformChartColour,
  platformOutline,
  platformSeriesColour,
} from './platformColours';

/** The approved brand order and values (brand kit, platform palette). */
const APPROVED: Record<string, string> = {
  meta: '#c24a2c',
  google_ads: '#f5f5f5',
  snapchat: '#7a2e1e',
  tiktok: '#a3a3a3',
  linkedin: '#e9a08a',
  x: '#4a4a4a',
};
const TOKEN: Record<string, string> = {
  meta: 'meta',
  google_ads: 'google',
  snapchat: 'snapchat',
  tiktok: 'tiktok',
  linkedin: 'linkedin',
  x: 'x',
};

describe('platform chart colours', () => {
  it('keeps the owner order', () => {
    expect([...PLATFORM_ORDER]).toEqual([
      'snapchat',
      'tiktok',
      'meta',
      'google_ads',
      'x',
      'linkedin',
    ]);
  });

  it.each(PLATFORM_ORDER)('declares the approved value for %s in the tokens', (provider) => {
    const name = TOKEN[provider];
    const declared = new RegExp(`--ds-platform-${name}:\\s*(#[0-9a-f]{6});`, 'i').exec(tokens)?.[1];

    expect(declared?.toLowerCase()).toBe(APPROVED[provider]);
    expect(platformChartColour(provider)).toBe(`var(--ds-platform-${name})`);
  });

  it('gives any other provider a neutral series colour instead of a platform one', () => {
    expect(platformChartColour('salla')).toBeNull();
    expect(platformSeriesColour('salla', 0)).toMatch(/^var\(--ds-chart-\d\)$/);
  });

  it('edges the marks that would otherwise vanish into a surface', () => {
    expect(platformOutline('google_ads')).toBeDefined();
    expect(platformOutline('meta')).toBeUndefined();
  });
});
