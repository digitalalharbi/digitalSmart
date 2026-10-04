import { useState, type ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { createQueryClient } from '@/shared/api/query-client';

export function AppProviders({ children }: { children: ReactNode }) {
  // One client per app instance; useState keeps it stable across re-renders.
  const [queryClient] = useState(createQueryClient);

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
