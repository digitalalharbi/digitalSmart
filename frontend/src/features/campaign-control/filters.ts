import type { ControlFilters, HealthValue, SortKey } from './types';

const HEALTH: HealthValue[] = [
  'healthy',
  'watch',
  'needs_attention',
  'critical',
  'inactive',
  'insufficient_data',
];
export const PERIODS = [7, 14, 28, 30, 56, 90] as const;
const SORTS: SortKey[] = ['health', 'spend', 'name', 'cost', 'roas'];

export const DEFAULT_FILTERS: ControlFilters = {
  client: null,
  project: null,
  account: null,
  mine: false,
  days: null,
  mode: 'summary',
  provider: null,
  status: null,
  objective: null,
  health: null,
  tag: null,
  search: '',
  sort: 'health',
  descending: false,
  offset: 0,
  view: 'cards',
};

/**
 * The filters, as a URL.
 *
 * A filtered view somebody can paste into a message is the cheapest "saved view" there is, and it is the
 * one that survives a reload. Entities travel as `id~label` so a restored page can show the chip without
 * a second request to learn what the id was called.
 */
export function fromParams(params: URLSearchParams): ControlFilters {
  const entity = (key: string) => {
    const raw = params.get(key);
    const at = raw?.indexOf('~') ?? -1;

    return raw !== null && at > 0 ? { id: raw.slice(0, at), label: raw.slice(at + 1) } : null;
  };
  const health = params.get('health') as HealthValue | null;
  const sort = params.get('sort') as SortKey | null;
  const offset = Number(params.get('offset') ?? 0);

  return {
    client: entity('client'),
    project: entity('project'),
    account: entity('account'),
    mine: params.get('mine') === '1',
    days: PERIODS.includes(Number(params.get('days')) as never) ? Number(params.get('days')) : null,
    mode: params.get('mode') === 'detailed' ? 'detailed' : 'summary',
    tag: entity('tag'),
    provider: params.get('provider'),
    status: params.get('status'),
    objective: params.get('objective'),
    health: health !== null && HEALTH.includes(health) ? health : null,
    search: params.get('q') ?? '',
    sort: sort !== null && SORTS.includes(sort) ? sort : 'health',
    descending: params.get('dir') === 'desc',
    offset: Number.isFinite(offset) && offset > 0 ? offset : 0,
    view: params.get('view') === 'table' ? 'table' : 'cards',
  };
}

export function toParams(filters: ControlFilters): URLSearchParams {
  const params = new URLSearchParams();
  const set = (key: string, value: string | null) => {
    if (value !== null && value !== '') {
      params.set(key, value);
    }
  };

  set('client', filters.client === null ? null : `${filters.client.id}~${filters.client.label}`);
  set(
    'project',
    filters.project === null ? null : `${filters.project.id}~${filters.project.label}`,
  );
  set(
    'account',
    filters.account === null ? null : `${filters.account.id}~${filters.account.label}`,
  );
  set('mine', filters.mine ? '1' : null);
  set('days', filters.days === null ? null : String(filters.days));
  set('mode', filters.mode === 'detailed' ? 'detailed' : null);
  set('tag', filters.tag === null ? null : `${filters.tag.id}~${filters.tag.label}`);
  set('provider', filters.provider);
  set('status', filters.status);
  set('objective', filters.objective);
  set('health', filters.health);
  set('q', filters.search);
  set('sort', filters.sort === 'health' ? null : filters.sort);
  set('dir', filters.descending ? 'desc' : null);
  set('offset', filters.offset > 0 ? String(filters.offset) : null);
  set('view', filters.view === 'table' ? 'table' : null);

  return params;
}
