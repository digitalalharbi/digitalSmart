import { api } from '@/shared/api/client';
import type { AdGroupFilters, AdGroupPage } from './types';

export const PAGE_SIZE = 24;

export async function fetchAdGroups(filters: AdGroupFilters): Promise<AdGroupPage> {
  const { data } = await api.get<AdGroupPage>('/ad-groups', {
    params: {
      limit: PAGE_SIZE,
      offset: filters.offset,
      sort: filters.sort,
      ...(filters.descending ? { direction: 'desc' } : {}),
      ...(filters.client === null ? {} : { client_id: filters.client.id }),
      ...(filters.project === null ? {} : { project_id: filters.project.id }),
      ...(filters.campaign === null ? {} : { campaign_id: filters.campaign.id }),
      ...(filters.mine ? { mine: 1 } : {}),
      ...(filters.days === null ? {} : { days: filters.days }),
      ...(filters.provider === null ? {} : { provider: filters.provider }),
      ...(filters.status === null ? {} : { status: filters.status }),
      ...(filters.zeroResults ? { no_results: 1 } : {}),
      ...(filters.search.trim() === '' ? {} : { search: filters.search.trim() }),
    },
  });

  return data;
}

/** One ad group, by id, over the same window — the ad group's own page reads the list endpoint, so its figures are the row's. */
export async function fetchAdGroup(id: string, days: number | null): Promise<AdGroupPage> {
  const { data } = await api.get<AdGroupPage>('/ad-groups', {
    params: { ad_group_id: [id], limit: 1, ...(days === null ? {} : { days }) },
  });

  return data;
}
