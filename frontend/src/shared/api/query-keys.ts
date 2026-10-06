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
  },
  audit: {
    all: () => ['audit'] as const,
    list: (params: Record<string, unknown>) => ['audit', 'list', params] as const,
  },
  integrations: {
    all: () => ['integrations'] as const,
    providers: () => ['integrations', 'providers'] as const,
    accounts: (provider: string) => ['integrations', 'accounts', provider] as const,
    bindings: (projectId: string) => ['integrations', 'bindings', projectId] as const,
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
  },
} as const;
