/**
 * The filters a visitor may narrow a shared report with, as they travel in the address bar.
 *
 * **Nothing here grants anything.** The token in the path is the authority; these are a request to see less of what it
 * already shows, and the server looks every value up inside the link's own scope. A copied filtered address therefore
 * opens the same report narrowed the same way — and an address with a value the report does not hold is refused and
 * falls back to the whole of it.
 *
 * Only plain, client-safe values live here: a platform's name, an opaque key the server handed out, a date. No id, no
 * figure, no name of a person.
 */
export type SharedFilterState = {
  platforms: string[];
  campaigns: string[];
  store: string | null;
  from: string | null;
  until: string | null;
  /** Opaque keys for creatives, and a format slug — both handed out by the server for this link. */
  creatives: string[];
  format: string | null;
};

export const NO_FILTERS: SharedFilterState = {
  platforms: [],
  campaigns: [],
  store: null,
  from: null,
  until: null,
  creatives: [],
  format: null,
};

const FORMAT = /^[a-z_]{1,32}$/;

const KEY = /^[A-Za-z0-9_-]{1,64}$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

function list(raw: string | null): string[] {
  return [
    ...new Set(
      (raw ?? '')
        .split(',')
        .map((value) => value.trim())
        .filter((value) => KEY.test(value)),
    ),
  ]
    .sort()
    .slice(0, 20);
}

/** Read the filters out of a query string. Anything that is not a plain key or a date is dropped, not carried. */
export function parseFilters(params: URLSearchParams): SharedFilterState {
  const store = params.get('store');
  const from = params.get('from');
  const until = params.get('until');
  const format = params.get('format');

  return {
    platforms: list(params.get('platform')),
    campaigns: list(params.get('campaign')),
    store: store !== null && KEY.test(store) ? store : null,
    from: from !== null && DATE.test(from) ? from : null,
    until: until !== null && DATE.test(until) ? until : null,
    creatives: list(params.get('creative')),
    format: format !== null && FORMAT.test(format) ? format : null,
  };
}

/** The query string for the address bar: only what is set, in a fixed order, so equal filters give equal addresses. */
export function toSearch(filters: SharedFilterState): URLSearchParams {
  const params = new URLSearchParams();

  if (filters.platforms.length > 0) {
    params.set('platform', [...filters.platforms].sort().join(','));
  }

  if (filters.campaigns.length > 0) {
    params.set('campaign', [...filters.campaigns].sort().join(','));
  }

  if (filters.store !== null) {
    params.set('store', filters.store);
  }

  if (filters.from !== null) {
    params.set('from', filters.from);
  }

  if (filters.until !== null) {
    params.set('until', filters.until);
  }

  if (filters.creatives.length > 0) {
    params.set('creative', [...filters.creatives].sort().join(','));
  }

  if (filters.format !== null) {
    params.set('format', filters.format);
  }

  return params;
}

/** A stable string for a cache key: two equal filter states are one entry, and no two reports share one. */
export function filterKey(filters: SharedFilterState): string {
  return toSearch(filters).toString();
}

export function isFiltered(filters: SharedFilterState): boolean {
  return filterKey(filters) !== '';
}

/** What the server says this link can be narrowed by. Absent for a frozen report, which cannot be narrowed. */
export type SharedFilterOptions = {
  platforms: { key: string; label: string }[];
  campaigns: { key: string; label: string }[];
  stores: { key: string; label: string }[];
  /** Offered only when the report shows creatives, and only where there is more than one to choose between. */
  creatives?: { key: string; label: string }[];
  formats?: { key: string; label: string }[];
  period: { from: string; until: string };
};

export type SharedFilterInfo = {
  can_filter: boolean;
  available: SharedFilterOptions | null;
  applied: unknown;
};

/** Whether there is anything at all to offer. A dimension with one value is not a filter, so a bare report has none. */
export function hasOptions(info: SharedFilterInfo | null | undefined): boolean {
  if (info == null || !info.can_filter || info.available === null) {
    return false;
  }

  const { platforms, campaigns, stores, period } = info.available;

  return (
    platforms.length > 0 || campaigns.length > 0 || stores.length > 0 || period.from < period.until
  );
}
