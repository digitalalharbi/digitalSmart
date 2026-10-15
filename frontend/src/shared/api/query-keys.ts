/**
 * Central query-key registry. Keys live here (not inline in hooks) so cache
 * invalidation across features stays correct and reviewable.
 */
export const queryKeys = {
  system: {
    health: () => ['system', 'health'] as const,
  },
  auth: {
    session: () => ['auth', 'session'] as const,
  },
  clients: {
    all: () => ['clients'] as const,
    list: (params: Record<string, unknown>) => ['clients', 'list', params] as const,
    detail: (id: string) => ['clients', 'detail', id] as const,
  },
  members: {
    all: () => ['members'] as const,
    list: (params: Record<string, unknown>) => ['members', 'list', params] as const,
    /** What one person still holds open, read before the removal that releases it. */
    heldWork: (userId: string) => ['members', 'held-work', userId] as const,
  },
  audit: {
    all: () => ['audit'] as const,
    list: (params: Record<string, unknown>) => ['audit', 'list', params] as const,
  },
  integrations: {
    all: () => ['integrations'] as const,
    providers: () => ['integrations', 'providers'] as const,
    connections: () => ['integrations', 'connections'] as const,
    queue: (states: string[]) => ['integrations', 'queue', states] as const,
    operations: () => ['integrations', 'operations'] as const,
    accounts: (provider: string) => ['integrations', 'accounts', provider] as const,
    bindings: (projectId: string) => ['integrations', 'bindings', projectId] as const,
  },
  portfolio: {
    view: (clientId: string | null) => ['portfolio', clientId] as const,
    tracking: () => ['portfolio', 'tracking'] as const,
  },
  budgetControl: {
    list: (risk: string | null) => ['budget-control', risk] as const,
  },
  home: {
    profiles: () => ['home', 'profiles'] as const,
    digests: () => ['home', 'digests'] as const,
    campaigns: (params: Record<string, unknown>) => ['home', 'campaigns', params] as const,
    budgets: () => ['home', 'budgets'] as const,
    creative: () => ['home', 'creative'] as const,
  },
  shortcuts: {
    all: () => ['shortcuts'] as const,
  },
  search: {
    query: (params: Record<string, unknown>) => ['search', params] as const,
  },
  campaignControl: {
    all: () => ['campaign-control'] as const,
    list: (params: Record<string, unknown>) => ['campaign-control', 'list', params] as const,
  },
  attention: {
    all: () => ['attention'] as const,
    queue: (params: Record<string, unknown>) => ['attention', 'queue', params] as const,
  },
  analytics: {
    all: () => ['analytics'] as const,
    query: (params: Record<string, unknown>) => ['analytics', 'query', params] as const,
    catalogue: () => ['analytics', 'catalogue'] as const,
  },
  dashboards: {
    all: () => ['dashboards'] as const,
    list: () => ['dashboards', 'list'] as const,
    detail: (id: string) => ['dashboards', 'detail', id] as const,
  },
  budgets: {
    all: () => ['budgets'] as const,
    list: (params: Record<string, unknown>) => ['budgets', 'list', params] as const,
    detail: (id: string) => ['budgets', 'detail', id] as const,
    pacing: (id: string) => ['budgets', 'pacing', id] as const,
    alerts: () => ['budgets', 'alerts'] as const,
    /*
     * The forecast is keyed by its whole input, because it is a pure function of it:
     * the same question gets the cached answer and a changed assumption gets a fresh
     * one, which is what a reader trying numbers expects.
     */
    forecast: (params: Record<string, unknown>) => ['budgets', 'forecast', params] as const,
  },
  commerce: {
    all: () => ['commerce'] as const,
    /*
     * Keyed by the whole query, revenue source included. Changing the source does not refine the answer,
     * it asks a different question — and caching two authorities under one key would show one total under
     * the other's label.
     */
    analytics: (query: Record<string, unknown>) => ['commerce', 'analytics', query] as const,
    /** Which stores this workspace reads. Scoped, because the page's client filter narrows it. */
    stores: (clientId: string | null) => ['commerce', 'stores', clientId ?? 'all'] as const,
    /** What the connections can read. Not scoped: it is a question about the platform, not a client. */
    connectableStores: () => ['commerce', 'stores', 'connectable'] as const,
  },
  measurement: {
    all: () => ['measurement'] as const,
    /*
     * Health is keyed by scope and nothing else. It is recomputed server-side on every read, so
     * caching it per scope is the most that can honestly be held: a key that outlived a sync
     * would show a diagnosis of a state that no longer exists.
     */
    health: (scope: Record<string, unknown>) => ['measurement', 'health', scope] as const,
    properties: (provider: string) => ['measurement', 'properties', provider] as const,
    suggestions: (sourceId: string) => ['measurement', 'suggestions', sourceId] as const,
    containers: () => ['measurement', 'containers'] as const,
    readiness: (sourceId: string) => ['measurement', 'readiness', sourceId] as const,
  },
  creative: {
    all: () => ['creative'] as const,
    vocabulary: () => ['creative', 'vocabulary'] as const,
    specs: () => ['creative', 'specs'] as const,
    /** Who one brief can be put in the name of. Per request, because it depends on its client. */
    owners: (requestId: string) => ['creative', 'owners', requestId] as const,
    board: (scope: Record<string, unknown>) => ['creative', 'board', scope] as const,
    /*
     * One key per request. A request carries its versions' spec verdicts, which are composed against the
     * newest recorded specification — so two requests must not share a cache entry and a stale one must not
     * outlive a write.
     */
    request: (requestId: string) => ['creative', 'request', requestId] as const,
  },
  attribution: {
    all: () => ['attribution'] as const,
    models: () => ['attribution', 'models'] as const,
    /*
     * Keyed by the whole query, model and revenue source included. Changing either does not refine
     * the answer, it asks a different question — and caching two models under one key would show one
     * model's figures under the other's label.
     */
    answer: (query: Record<string, unknown>) => ['attribution', 'answer', query] as const,
    comparison: (query: Record<string, unknown>) => ['attribution', 'comparison', query] as const,
  },
  leads: {
    all: () => ['leads'] as const,
    centre: (scope: Record<string, unknown>) => ['leads', 'centre', scope] as const,
    list: (filter: Record<string, unknown>) => ['leads', 'list', filter] as const,
    /*
     * One key per lead. A journey is an assertion about who somebody is, assembled from the
     * records as they stand, so it is never shared between leads or held across a change.
     */
    journey: (leadId: string) => ['leads', 'journey', leadId] as const,
    /**
     * The leads that may be the same person as this one.
     *
     * Keyed per lead and never held long: the list is what somebody is about to act on irreversibly, and a
     * stale row would offer a merge against a record that has already been merged away.
     */
    duplicates: (leadId: string) => ['leads', 'duplicates', leadId] as const,
    /** What has happened to one lead. Invalidated by anything that writes to it. */
    history: (leadId: string) => ['leads', 'history', leadId] as const,
    /** Who a lead can be put in the name of. Per lead, because it depends on the lead's client. */
    owners: (leadId: string) => ['leads', 'owners', leadId] as const,
  },
  tasks: {
    all: () => ['tasks'] as const,
    vocabulary: () => ['tasks', 'vocabulary'] as const,
    /** Who one task can be given to. Per task, because it depends on the task's client. */
    assignees: (taskId: string) => ['tasks', 'assignees', taskId] as const,
    /*
     * Keyed by the whole scope. Narrowing the board does not refine the answer, it asks a different
     * question — the figures beside the cards are computed over the same filter, so two scopes cached
     * under one key would show one scope's counts beside the other's cards.
     */
    board: (scope: Record<string, unknown>) => ['tasks', 'board', scope] as const,
    detail: (taskId: string) => ['tasks', 'detail', taskId] as const,
  },
  approvals: {
    all: () => ['approvals'] as const,
    vocabulary: () => ['approvals', 'vocabulary'] as const,
    queue: (scope: Record<string, unknown>) => ['approvals', 'queue', scope] as const,
    /*
     * One key per request, and never shared. A stale proposal in a cache is the one thing this module cannot
     * tolerate: the fingerprint on screen has to be the fingerprint of what is stored, or a decision is
     * refused for a reason the reader cannot see.
     */
    detail: (requestId: string) => ['approvals', 'detail', requestId] as const,
  },
  notifications: {
    all: () => ['notifications'] as const,
    vocabulary: () => ['notifications', 'vocabulary'] as const,
    /*
     * One key for the feed, and the unread filter is part of it: the filtered list and the whole list are
     * different answers, and the counts beside them are computed over everything either way.
     */
    feed: (unreadOnly: boolean) => ['notifications', 'feed', unreadOnly] as const,
  },
  clientRequests: {
    all: () => ['client-requests'] as const,
    vocabulary: () => ['client-requests', 'vocabulary'] as const,
    queue: (scope: Record<string, unknown>) => ['client-requests', 'queue', scope] as const,
    detail: (requestId: string) => ['client-requests', 'detail', requestId] as const,
    /** Who one request can be given to. Per request, because it depends on the request's client. */
    owners: (requestId: string) => ['client-requests', 'owners', requestId] as const,
  },
  exports: {
    mine: () => ['exports', 'mine'] as const,
  },
  reports: {
    all: () => ['reports'] as const,
    vocabulary: () => ['reports', 'vocabulary'] as const,
    list: (clientId: string | null) => ['reports', 'list', clientId] as const,
    /*
     * The rendered report and its definition are separate keys, because they are separate reads for a reason: one
     * costs an analytics aggregation per section and the other does not.
     */
    rendered: (reportId: string) => ['reports', 'rendered', reportId] as const,
    draft: (reportId: string) => ['reports', 'draft', reportId] as const,
    snapshots: (reportId: string) => ['reports', 'snapshots', reportId] as const,
    shares: (reportId: string) => ['reports', 'shares', reportId] as const,
    /*
     * A report read through a shared link. Keyed by the token because that is all the reader has — there is no
     * report id on that path, and deliberately so.
     */
    shared: (token: string) => ['reports', 'shared', token] as const,
  },
  portal: {
    all: () => ['portal'] as const,
    /* The agency's own view of the configuration. */
    vocabulary: () => ['portal', 'vocabulary'] as const,
    list: () => ['portal', 'list'] as const,
    detail: (clientId: string) => ['portal', 'detail', clientId] as const,
    /*
     * And the reader's own. Separate from the agency's keys because the two are different documents: invalidating
     * a configuration change must not leave a client's open page showing what it exposed a moment ago.
     */
    mine: () => ['portal', 'mine'] as const,
    performance: (clientId: string) => ['portal', 'mine', clientId, 'performance'] as const,
    reports: (clientId: string) => ['portal', 'mine', clientId, 'reports'] as const,
    /* Keyed by the authority counting, because two sources are two different answers about one period. */
    revenue: (clientId: string, revenueSource: string | null) =>
      ['portal', 'mine', clientId, 'revenue', revenueSource] as const,
    report: (clientId: string, reportId: string) =>
      ['portal', 'mine', clientId, 'report', reportId] as const,
    tracking: (clientId: string) => ['portal', 'mine', clientId, 'tracking'] as const,
  },
  assistant: {
    all: () => ['assistant'] as const,
    vocabulary: () => ['assistant', 'vocabulary'] as const,
    /*
     * Keyed by the client and the period, because those are the only two inputs: the analysers and their
     * thresholds are fixed, so the same client over the same days is the same answer.
     */
    analysis: (clientId: string, days: number) =>
      ['assistant', 'analysis', clientId, days] as const,
    finding: (clientId: string, days: number, code: string) =>
      ['assistant', 'finding', clientId, days, code] as const,
    proposal: (clientId: string, days: number) =>
      ['assistant', 'proposal', clientId, days] as const,
  },
  presentations: {
    all: () => ['presentations'] as const,
    vocabulary: () => ['presentations', 'vocabulary'] as const,
    /* Keyed by what composes the deck: a client for a report, a budget for a plan. */
    deck: (kind: string, clientId: string | null, budgetId: string | null) =>
      ['presentations', 'deck', kind, clientId, budgetId] as const,
  },
  savedViews: {
    all: () => ['saved-views'] as const,
    list: () => ['saved-views', 'list'] as const,
  },
  campaigns: {
    all: () => ['campaigns'] as const,
    list: (params: Record<string, unknown>) => ['campaigns', 'list', params] as const,
  },
  projects: {
    all: () => ['projects'] as const,
    list: (params: Record<string, unknown>) => ['projects', 'list', params] as const,
    detail: (id: string) => ['projects', 'detail', id] as const,
    members: (id: string) => ['projects', 'members', id] as const,
    /** Who could be given access to one project: active members not on it yet. */
    assignable: (id: string) => ['projects', 'assignable', id] as const,
  },
} as const;
