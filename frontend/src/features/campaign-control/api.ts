import { api } from '@/shared/api/client';
import type { CampaignControlPage, ControlFilters } from './types';

export const PAGE_SIZE = 24;

export async function fetchCampaignControl(filters: ControlFilters): Promise<CampaignControlPage> {
  const { data } = await api.get<CampaignControlPage>('/campaigns/control', {
    params: {
      limit: PAGE_SIZE,
      offset: filters.offset,
      sort: filters.sort,
      ...(filters.descending ? { direction: 'desc' } : {}),
      ...(filters.client === null ? {} : { client_id: filters.client.id }),
      ...(filters.project === null ? {} : { project_id: filters.project.id }),
      ...(filters.account === null ? {} : { external_account_id: filters.account.id }),
      ...(filters.mine ? { mine: 1 } : {}),
      ...(filters.days === null ? {} : { days: filters.days }),
      ...(filters.tag === null ? {} : { tag_id: filters.tag.id }),
      ...(filters.provider === null ? {} : { provider: filters.provider }),
      ...(filters.status === null ? {} : { status: filters.status }),
      ...(filters.objective === null ? {} : { objective: filters.objective }),
      ...(filters.health === null ? {} : { health: filters.health }),
      ...(filters.endsWithin === null ? {} : { ends_within: filters.endsWithin }),
      ...(filters.budget === null ? {} : { budget: filters.budget }),
      ...(filters.zeroResults ? { no_results: 1 } : {}),
      ...(filters.search.trim() === '' ? {} : { search: filters.search.trim() }),
    },
  });

  return data;
}
