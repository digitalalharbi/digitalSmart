import { describe, expect, it } from 'vitest';
import { DEFAULT_FILTERS, fromParams, toParams } from './filters';
import { adGroupsLinkFor } from './links';
import { adGroupsKey } from './useAdGroups';
import type { AdGroupFilters } from './types';

describe('ad group filters in the URL', () => {
  it('round-trips every filter, keeping an entity label so the chip needs no second request', () => {
    const filters: AdGroupFilters = {
      client: { id: 'c1', label: 'Alhambra Retail' },
      project: { id: 'p1', label: 'Ramadan ~ Push' },
      campaign: { id: 'k1', label: 'Always on' },
      mine: true,
      days: 14,
      provider: 'meta',
      status: 'active',
      zeroResults: true,
      search: 'lookalike',
      sort: 'roas',
      descending: true,
      offset: 48,
    };

    expect(fromParams(toParams(filters))).toEqual(filters);
  });

  it('writes nothing for the defaults, so a clean view has a clean URL', () => {
    expect(toParams(DEFAULT_FILTERS).toString()).toBe('');
  });

  it('ignores a value it does not recognise rather than trusting the address bar', () => {
    const parsed = fromParams(new URLSearchParams('sort=vibes&days=5&offset=-3'));

    expect(parsed.sort).toBe('spend');
    expect(parsed.days).toBeNull();
    expect(parsed.offset).toBe(0);
  });

  it('is what a campaign links to for its ad groups', () => {
    const parsed = fromParams(
      new URLSearchParams(adGroupsLinkFor({ id: 'k1', name: 'Always on' }).split('?')[1]),
    );

    expect(parsed.campaign).toEqual({ id: 'k1', label: 'Always on' });
  });
});

/** A filter missing from the cache key returns the previous answer: every one must change it. */
describe('the ad group list cache key', () => {
  const changes: Array<[string, Partial<AdGroupFilters>]> = [
    ['client', { client: { id: 'c', label: 'C' } }],
    ['project', { project: { id: 'p', label: 'P' } }],
    ['campaign', { campaign: { id: 'k', label: 'K' } }],
    ['mine', { mine: true }],
    ['days', { days: 14 }],
    ['provider', { provider: 'meta' }],
    ['status', { status: 'active' }],
    ['zeroResults', { zeroResults: true }],
    ['search', { search: 'x' }],
    ['sort', { sort: 'roas' }],
    ['descending', { descending: true }],
    ['offset', { offset: 24 }],
  ];

  it.each(changes)('changes when %s changes', (_name, patch) => {
    expect(adGroupsKey({ ...DEFAULT_FILTERS, ...patch })).not.toEqual(adGroupsKey(DEFAULT_FILTERS));
  });
});
