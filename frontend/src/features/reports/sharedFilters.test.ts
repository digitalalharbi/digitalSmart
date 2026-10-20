import { describe, expect, it } from 'vitest';
import {
  filterKey,
  hasOptions,
  isFiltered,
  NO_FILTERS,
  parseFilters,
  toSearch,
  type SharedFilterInfo,
} from './sharedFilters';

describe('parseFilters', () => {
  it('reads plain keys and dates, and drops anything else rather than carrying it', () => {
    const filters = parseFilters(
      new URLSearchParams(
        'platform=meta,google_ads,<script>&campaign=abc123&store=s1&from=2026-10-01&until=not-a-date',
      ),
    );

    expect(filters).toEqual({
      platforms: ['google_ads', 'meta'],
      campaigns: ['abc123'],
      store: 's1',
      from: '2026-10-01',
      until: null,
    });
  });

  it('ignores every parameter that is not a filter, so an address cannot carry anything else in', () => {
    const filters = parseFilters(new URLSearchParams('client_id=other&report=x&token=y&refine=1'));

    expect(filters).toEqual(NO_FILTERS);
    expect(isFiltered(filters)).toBe(false);
  });
});

describe('toSearch and filterKey', () => {
  it('writes only what is set, in a fixed order, so equal filters are equal addresses', () => {
    const a = toSearch({ ...NO_FILTERS, platforms: ['meta', 'google_ads'], from: '2026-10-01' });
    const b = toSearch({ ...NO_FILTERS, from: '2026-10-01', platforms: ['google_ads', 'meta'] });

    expect(a.toString()).toBe(b.toString());
    expect(filterKey(NO_FILTERS)).toBe('');
    expect(filterKey({ ...NO_FILTERS, store: 'k' })).toBe('store=k');
  });

  it('round-trips', () => {
    const state = {
      platforms: ['meta'],
      campaigns: ['a1', 'b2'],
      store: 's',
      from: '2026-10-01',
      until: '2026-10-05',
    };

    expect(parseFilters(toSearch(state))).toEqual(state);
  });
});

describe('hasOptions', () => {
  const info = (available: SharedFilterInfo['available']): SharedFilterInfo => ({
    can_filter: true,
    available,
    applied: null,
  });
  const period = { from: '2026-10-01', until: '2026-10-07' };

  it('offers a period on any report longer than a day, and nothing on a bare one-day one', () => {
    expect(hasOptions(info({ platforms: [], campaigns: [], stores: [], period }))).toBe(true);
    expect(
      hasOptions(
        info({
          platforms: [],
          campaigns: [],
          stores: [],
          period: { from: '2026-10-01', until: '2026-10-01' },
        }),
      ),
    ).toBe(false);
  });

  it('offers nothing for a frozen report or a dead link', () => {
    expect(hasOptions({ can_filter: false, available: null, applied: null })).toBe(false);
    expect(hasOptions(null)).toBe(false);
    expect(hasOptions(undefined)).toBe(false);
  });
});
