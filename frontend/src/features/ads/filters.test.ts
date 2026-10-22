import { describe, expect, it } from 'vitest';
import { DEFAULT_FILTERS, fromParams, toParams, type AdFilters } from './filters';
import { adsInCampaignLink, adsInGroupLink } from './links';
import { adsQuery } from './useAds';

describe('ad filters in the URL', () => {
  it('round-trips every filter, keeping an entity label so the chip needs no second request', () => {
    const filters: AdFilters = {
      client: { id: 'c1', label: 'Alhambra Retail' },
      project: { id: 'p1', label: 'Ramadan ~ Push' },
      campaign: { id: 'k1', label: 'Always on' },
      adGroup: { id: 'g1', label: 'Lookalike 1%' },
      days: 14,
      provider: 'meta',
      status: 'active',
      verdict: 'fatiguing',
      format: 'video',
      view: 'table',
      offset: 48,
    };

    expect(fromParams(toParams(filters))).toEqual(filters);
  });

  it('writes nothing for the defaults, and opens on the pictures', () => {
    expect(toParams(DEFAULT_FILTERS).toString()).toBe('');
    expect(DEFAULT_FILTERS.view).toBe('media');
  });

  it('ignores a value it does not recognise rather than trusting the address bar', () => {
    const parsed = fromParams(new URLSearchParams('verdict=vibes&days=5&offset=-3&view=wall'));

    expect(parsed.verdict).toBeNull();
    expect(parsed.days).toBeNull();
    expect(parsed.offset).toBe(0);
    expect(parsed.view).toBe('media');
  });

  it('is what an ad group and a campaign link to for their ads', () => {
    expect(
      fromParams(new URLSearchParams(adsInGroupLink({ id: 'g1', name: 'Lookalike' }).split('?')[1]))
        .adGroup,
    ).toEqual({ id: 'g1', label: 'Lookalike' });
    expect(
      fromParams(
        new URLSearchParams(adsInCampaignLink({ id: 'k1', name: 'Always on' }).split('?')[1]),
      ).campaign,
    ).toEqual({ id: 'k1', label: 'Always on' });
  });
});

/** The query is the creative layer's, at the grain of an ad — every filter must reach it, or it narrows nothing. */
describe('the ads query', () => {
  it('asks for ads, not assets, and carries every filter the reader chose', () => {
    const query = adsQuery(
      {
        ...DEFAULT_FILTERS,
        client: { id: 'c', label: 'C' },
        project: { id: 'p', label: 'P' },
        campaign: { id: 'k', label: 'K' },
        adGroup: { id: 'g', label: 'G' },
        provider: 'meta',
        status: 'paused',
        verdict: 'weak',
        format: 'image',
        days: 14,
        offset: 24,
      },
      30,
    );

    expect(query).toMatchObject({
      preset: '14d',
      grain: 'ad',
      client_id: 'c',
      project_ids: ['p'],
      campaign_ids: ['k'],
      ad_group_ids: ['g'],
      providers: ['meta'],
      ad_status: 'paused',
      verdict: 'weak',
      format: 'image',
      offset: 24,
    });
  });

  it('reads the shared period when the address names none', () => {
    expect(adsQuery(DEFAULT_FILTERS, 30)).toMatchObject({ preset: '30d' });
    expect(adsQuery(DEFAULT_FILTERS, 7)).toMatchObject({ preset: '7d' });
  });
});
