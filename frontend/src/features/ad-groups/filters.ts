import type { AdGroupFilters, AdGroupSort } from './types';

export const PERIODS = [7, 14, 28, 30, 56, 90] as const;
const SORTS: AdGroupSort[] = ['spend', 'name', 'cost', 'roas', 'ctr'];

export const DEFAULT_FILTERS: AdGroupFilters = {
  client: null,
  project: null,
  campaign: null,
  mine: false,
  days: null,
  provider: null,
  status: null,
  zeroResults: false,
  search: '',
  sort: 'spend',
  descending: false,
  offset: 0,
};

/**
 * The filters, as a URL — so a filtered view can be pasted into a message, and so the page a campaign links to
 * ("its ad groups") is just this page with one filter set. Entities travel as `id~label` so a restored page can show
 * the chip without a second request to learn what the id was called.
 */
export function fromParams(params: URLSearchParams): AdGroupFilters {
  const entity = (key: string) => {
    const raw = params.get(key);
    const at = raw?.indexOf('~') ?? -1;

    return raw !== null && at > 0 ? { id: raw.slice(0, at), label: raw.slice(at + 1) } : null;
  };
  const sort = params.get('sort') as AdGroupSort | null;
  const offset = Number(params.get('offset') ?? 0);

  return {
    client: entity('client'),
    project: entity('project'),
    campaign: entity('campaign'),
    mine: params.get('mine') === '1',
    days: PERIODS.includes(Number(params.get('days')) as never) ? Number(params.get('days')) : null,
    provider: params.get('provider'),
    status: params.get('status'),
    zeroResults: params.get('zero') === '1',
    search: params.get('q') ?? '',
    sort: sort !== null && SORTS.includes(sort) ? sort : 'spend',
    descending: params.get('dir') === 'desc',
    offset: Number.isFinite(offset) && offset > 0 ? offset : 0,
  };
}

export function toParams(filters: AdGroupFilters): URLSearchParams {
  const params = new URLSearchParams();
  const set = (key: string, value: string | null) => {
    if (value !== null && value !== '') {
      params.set(key, value);
    }
  };
  const entity = (value: { id: string; label: string } | null) =>
    value === null ? null : `${value.id}~${value.label}`;

  set('client', entity(filters.client));
  set('project', entity(filters.project));
  set('campaign', entity(filters.campaign));
  set('mine', filters.mine ? '1' : null);
  set('days', filters.days === null ? null : String(filters.days));
  set('provider', filters.provider);
  set('status', filters.status);
  set('zero', filters.zeroResults ? '1' : null);
  set('q', filters.search);
  set('sort', filters.sort === 'spend' ? null : filters.sort);
  set('dir', filters.descending ? 'desc' : null);
  set('offset', filters.offset > 0 ? String(filters.offset) : null);

  return params;
}
