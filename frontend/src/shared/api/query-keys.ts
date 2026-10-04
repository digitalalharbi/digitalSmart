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
  projects: {
    all: () => ['projects'] as const,
    list: (params: Record<string, unknown>) => ['projects', 'list', params] as const,
    detail: (id: string) => ['projects', 'detail', id] as const,
    members: (id: string) => ['projects', 'members', id] as const,
  },
} as const;
