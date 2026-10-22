export const PERIODS = [7, 14, 28, 30, 90] as const;
export const PRESET: Record<number, '7d' | '14d' | '28d' | '30d' | '90d'> = {
  7: '7d',
  14: '14d',
  28: '28d',
  30: '30d',
  90: '90d',
};

export type AdFilters = {
  client: { id: string; label: string } | null;
  project: { id: string; label: string } | null;
  campaign: { id: string; label: string } | null;
  adGroup: { id: string; label: string } | null;
  days: number | null;
  provider: string | null;
  /** The ad's own status. */
  status: string | null;
  /** The creative's reading: top, rising, steady, fatiguing, replace. */
  verdict: string | null;
  format: string | null;
  view: 'media' | 'table';
  offset: number;
};

export const DEFAULT_FILTERS: AdFilters = {
  client: null,
  project: null,
  campaign: null,
  adGroup: null,
  days: null,
  provider: null,
  status: null,
  verdict: null,
  format: null,
  /* An ad is a picture first: the media view is the default and the table is one press away. */
  view: 'media',
  offset: 0,
};

const VERDICTS = ['top', 'rising', 'stable', 'fatiguing', 'weak'];

/**
 * The filters, as a URL: a filtered view can be pasted into a message, and the pages that lead here ("this ad
 * group's ads") are this page with one filter set. Entities travel as `id~label` so a restored page shows its chip
 * without a second request.
 */
export function fromParams(params: URLSearchParams): AdFilters {
  const entity = (key: string) => {
    const raw = params.get(key);
    const at = raw?.indexOf('~') ?? -1;

    return raw !== null && at > 0 ? { id: raw.slice(0, at), label: raw.slice(at + 1) } : null;
  };
  const verdict = params.get('verdict');
  const offset = Number(params.get('offset') ?? 0);

  return {
    client: entity('client'),
    project: entity('project'),
    campaign: entity('campaign'),
    adGroup: entity('group'),
    days: PERIODS.includes(Number(params.get('days')) as never) ? Number(params.get('days')) : null,
    provider: params.get('provider'),
    status: params.get('status'),
    verdict: verdict !== null && VERDICTS.includes(verdict) ? verdict : null,
    format: params.get('format'),
    view: params.get('view') === 'table' ? 'table' : 'media',
    offset: Number.isFinite(offset) && offset > 0 ? offset : 0,
  };
}

export function toParams(filters: AdFilters): URLSearchParams {
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
  set('group', entity(filters.adGroup));
  set('days', filters.days === null ? null : String(filters.days));
  set('provider', filters.provider);
  set('status', filters.status);
  set('verdict', filters.verdict);
  set('format', filters.format);
  set('view', filters.view === 'table' ? 'table' : null);
  set('offset', filters.offset > 0 ? String(filters.offset) : null);

  return params;
}
