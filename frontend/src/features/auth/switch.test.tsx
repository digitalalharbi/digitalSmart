import { describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import type * as Client from '@/shared/api/client';
import { useUiStore } from '@/shared/store/ui-store';
import { useSwitchWorkspaceMutation } from './api';

vi.mock('@/shared/api/client', async (importOriginal) => {
  const original = await importOriginal<typeof Client>();

  return {
    ...original,
    ensureCsrfCookie: () => Promise.resolve(),
    api: {
      post: () => Promise.resolve({ data: { data: { id: 'me', active_workspace: { id: 'w2' } } } }),
    },
  };
});

/*
 * Switching tenants must not leave the previous tenant on screen: its cached reads are removed (not refreshed in place),
 * and a client or project chosen in the old workspace is forgotten.
 */
describe('switching workspace', () => {
  it('removes every cached read of the previous workspace and clears the working context', async () => {
    const client = new QueryClient();
    client.setQueryData(['clients', 'list'], { data: [{ name: 'Old tenant client' }] });
    client.setQueryData(['auth', 'session'], { id: 'me', active_workspace: { id: 'w1' } });
    useUiStore
      .getState()
      .setContext({ client: { id: 'c1', label: 'Old tenant client' }, project: null });

    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
    const { result } = renderHook(() => useSwitchWorkspaceMutation(), { wrapper });

    act(() => {
      result.current.mutate('w2');
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(client.getQueryData(['clients', 'list'])).toBeUndefined();
    expect(useUiStore.getState().context).toEqual({ client: null, project: null });
  });
});
