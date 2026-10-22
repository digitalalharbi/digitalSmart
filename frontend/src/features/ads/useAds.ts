import { useCreatives } from '@/features/creatives';
import type { CreativeQuery } from '@/features/creatives';
import { PRESET, type AdFilters } from './filters';

export const PAGE_SIZE = 24;

/**
 * The query for the Ads screen: the creative layer at the grain of an ad.
 *
 * Not a second engine. An ad's figures, its picture, its fatigue verdict and its evidence are the creative layer's,
 * so a creative's number here is the number the Creatives page and a client's report give for the same ad.
 */
export function adsQuery(filters: AdFilters, sharedDays: number): CreativeQuery {
  const days = filters.days ?? sharedDays;

  return {
    preset: PRESET[days] ?? '28d',
    grain: 'ad',
    limit: PAGE_SIZE,
    offset: filters.offset,
    ...(filters.client === null ? {} : { client_id: filters.client.id }),
    ...(filters.project === null ? {} : { project_ids: [filters.project.id] }),
    ...(filters.campaign === null ? {} : { campaign_ids: [filters.campaign.id] }),
    ...(filters.adGroup === null ? {} : { ad_group_ids: [filters.adGroup.id] }),
    ...(filters.provider === null ? {} : { providers: [filters.provider] }),
    ...(filters.status === null ? {} : { ad_status: filters.status }),
    ...(filters.verdict === null ? {} : { verdict: filters.verdict }),
    ...(filters.format === null ? {} : { format: filters.format }),
  };
}

export function useAds(filters: AdFilters, sharedDays: number) {
  return useCreatives(adsQuery(filters, sharedDays));
}
