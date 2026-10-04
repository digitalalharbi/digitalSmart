import { QueryClient } from '@tanstack/react-query';
import { ApiError } from './client';

/**
 * Query defaults tuned for an analytics product: data is cached long enough to
 * keep navigation instant, but never retried when the server has already given
 * a definitive answer (auth, validation, not-found).
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 60_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          if (error instanceof ApiError) {
            const status = error.status;
            if (status !== null && status >= 400 && status < 500) {
              return false;
            }
          }
          return failureCount < 2;
        },
      },
      mutations: {
        retry: false,
      },
    },
  });
}
