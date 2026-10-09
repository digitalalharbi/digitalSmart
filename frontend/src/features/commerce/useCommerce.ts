import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  connectStore,
  fetchConnectableStores,
  fetchStoreAnalytics,
  fetchStores,
  readStore,
} from './api';
import type { CommerceQuery, ConnectableStore, Store, StoreDraft, StoreReadResult } from './types';

/**
 * What a client's stores sold, according to one named authority.
 *
 * Keyed by the whole query including the revenue source, because changing the source does not refine the
 * answer — it asks a different question. Caching the two together under one key would show a Salla total
 * while the label said GA4.
 */
export function useStoreAnalyticsQuery(query: CommerceQuery, enabled = true) {
  return useQuery({
    queryKey: queryKeys.commerce.analytics(query),
    queryFn: () => fetchStoreAnalytics(query),
    enabled,
    staleTime: 60_000,
  });
}

/** Which stores this workspace reads, for the client in scope or all of them. */
export function useStoresQuery(clientId: string | null) {
  return useQuery({
    queryKey: queryKeys.commerce.stores(clientId),
    queryFn: (): Promise<Store[]> => fetchStores(clientId),
    staleTime: 30_000,
  });
}

/**
 * Every merchant the workspace's commerce connections can read.
 *
 * Only while somebody is looking: this spends the workspace's credentials on the platform's API, which is
 * not a call to make on the chance a panel will be opened.
 */
export function useConnectableStoresQuery(enabled: boolean) {
  return useQuery({
    queryKey: queryKeys.commerce.connectableStores(),
    enabled,
    queryFn: (): Promise<ConnectableStore[]> => fetchConnectableStores(),
    staleTime: 60_000,
  });
}

/**
 * Everything commerce, invalidated together.
 *
 * Connecting a store changes which stores exist *and* which merchants are still connectable; reading one
 * changes the figures as well as the store's own timestamps. Enumerating which pairs interact would be a
 * second model of the same relationships.
 */
function useInvalidateCommerce() {
  const client = useQueryClient();

  return () => client.invalidateQueries({ queryKey: queryKeys.commerce.all() });
}

export function useConnectStoreMutation() {
  const invalidate = useInvalidateCommerce();

  return useMutation({
    mutationFn: (draft: StoreDraft): Promise<Store> => connectStore(draft),
    onSuccess: () => void invalidate(),
  });
}

/**
 * Read one store now.
 *
 * The result is handed back rather than reduced to success: a partial read wrote real orders and keeps them,
 * a skipped one means the platform has no adapter to read with, and a failure names what the platform said.
 */
export function useReadStoreMutation() {
  const invalidate = useInvalidateCommerce();

  return useMutation({
    mutationFn: (storeId: string): Promise<StoreReadResult> => readStore(storeId),
    onSuccess: () => void invalidate(),
  });
}
