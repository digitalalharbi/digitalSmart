import { describe, expect, it } from 'vitest';
import { DEFAULT_FILTERS, fromParams, toParams } from './filters';

describe('control-center filters in the URL', () => {
  it('round-trips every filter, keeping an entity label so the chip needs no second request', () => {
    const filters = {
      ...DEFAULT_FILTERS,
      client: { id: 'c1', label: 'Alhambra Retail' },
      project: { id: 'p1', label: 'Ramadan ~ Push' },
      account: { id: 'a1', label: 'Meta KSA' },
      mine: true,
      days: 14,
      mode: 'detailed' as const,
      provider: 'meta',
      health: 'critical' as const,
      search: 'ramadan',
      sort: 'spend' as const,
      descending: true,
      offset: 48,
      view: 'table' as const,
    };

    expect(fromParams(toParams(filters))).toEqual(filters);
  });

  it('writes nothing for the defaults, so a clean view has a clean URL', () => {
    expect(toParams(DEFAULT_FILTERS).toString()).toBe('');
  });

  it('ignores a value it does not recognise rather than trusting the address bar', () => {
    const parsed = fromParams(new URLSearchParams('health=excellent&sort=vibes&offset=-5'));

    expect(parsed.health).toBeNull();
    expect(parsed.sort).toBe('health');
    expect(parsed.offset).toBe(0);
  });
});
