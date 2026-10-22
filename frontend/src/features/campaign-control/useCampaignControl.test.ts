import { describe, expect, it } from 'vitest';
import { DEFAULT_FILTERS } from './filters';
import { campaignControlKey } from './useCampaignControl';
import type { ControlFilters } from './types';

/**
 * A filter missing from the cache key is a filter that returns the previous answer: the reader narrows the list and
 * sees the page they already had. Every field that changes what the server is asked must change the key.
 */
describe('the campaign list cache key', () => {
  const changes: Array<[string, Partial<ControlFilters>]> = [
    ['client', { client: { id: 'c', label: 'C' } }],
    ['project', { project: { id: 'p', label: 'P' } }],
    ['account', { account: { id: 'a', label: 'A' } }],
    ['mine', { mine: true }],
    ['days', { days: 14 }],
    ['provider', { provider: 'meta' }],
    ['status', { status: 'active' }],
    ['objective', { objective: 'leads' }],
    ['health', { health: 'critical' }],
    ['tag', { tag: { id: 't', label: 'T' } }],
    ['endsWithin', { endsWithin: 7 }],
    ['budget', { budget: 'high' }],
    ['zeroResults', { zeroResults: true }],
    ['search', { search: 'ramadan' }],
    ['sort', { sort: 'spend' }],
    ['descending', { descending: true }],
    ['offset', { offset: 24 }],
  ];

  it.each(changes)('changes when %s changes', (_name, patch) => {
    expect(campaignControlKey({ ...DEFAULT_FILTERS, ...patch })).not.toEqual(
      campaignControlKey(DEFAULT_FILTERS),
    );
  });
});
