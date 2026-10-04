import type { ReactElement, ReactNode } from 'react';
import { render, type RenderOptions, type RenderResult } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { I18nextProvider } from 'react-i18next';
import { MemoryRouter } from 'react-router';
import { i18next, initI18n } from '@/shared/i18n';

/** Retries are disabled so failure assertions do not wait on backoff. */
function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  });
}

export async function renderWithProviders(
  ui: ReactElement,
  options: Omit<RenderOptions, 'wrapper'> & { route?: string } = {},
): Promise<RenderResult & { queryClient: QueryClient }> {
  await initI18n('en');
  const queryClient = createTestQueryClient();
  const { route = '/', ...renderOptions } = options;

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <I18nextProvider i18n={i18next}>
          <MemoryRouter initialEntries={[route]}>{children}</MemoryRouter>
        </I18nextProvider>
      </QueryClientProvider>
    );
  }

  return { ...render(ui, { wrapper: Wrapper, ...renderOptions }), queryClient };
}
