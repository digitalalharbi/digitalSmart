import { api, ensureCsrfCookie } from '@/shared/api/client';
import type {
  CommerceQuery,
  ConnectableStore,
  Store,
  StoreDraft,
  StoreReadResult,
  StoreAnalytics,
} from './types';

export async function fetchStoreAnalytics(query: CommerceQuery): Promise<StoreAnalytics> {
  const { data } = await api.get<{ data: StoreAnalytics }>('/commerce/analytics', {
    params: {
      // Always sent. The server refuses a request without it, and so it should.
      revenue_source: query.revenue_source,
      ...(query.from === undefined ? {} : { from: query.from }),
      ...(query.until === undefined ? {} : { until: query.until }),
      ...(query.client_id == null ? {} : { client_id: query.client_id }),
      ...(query.limit === undefined ? {} : { limit: query.limit }),
    },
  });

  return data.data;
}

/** The stores this workspace reads, and when each was last read. */
export async function fetchStores(clientId: string | null): Promise<Store[]> {
  const { data } = await api.get<{ data: Store[] }>('/commerce/stores', {
    params: clientId == null ? {} : { client_id: clientId },
  });

  return data.data;
}

/**
 * Every merchant the workspace's commerce connections can read.
 *
 * Empty when nothing is connected, which is the ordinary state rather than a failure: the integrations page
 * is where a platform is connected.
 */
export async function fetchConnectableStores(): Promise<ConnectableStore[]> {
  const { data } = await api.get<{ data: ConnectableStore[] }>('/commerce/stores/connectable');

  return data.data;
}

/**
 * Say that this merchant's orders are this client's revenue.
 *
 * Only the provider, the merchant id and the client are sent. The name, currency, domain and timezone are
 * read back from the platform by the server — a typed currency would be the most expensive field in this
 * product to get wrong, because every amount from that store is stored in it.
 */
export async function connectStore(draft: StoreDraft): Promise<Store> {
  await ensureCsrfCookie();
  const { data } = await api.post<{ data: Store }>('/commerce/stores', draft);

  return data.data;
}

/** Read one store now. Returns the outcome, which may be partial or failed. */
export async function readStore(storeId: string): Promise<StoreReadResult> {
  await ensureCsrfCookie();
  const { data } = await api.post<{ data: StoreReadResult }>(`/commerce/stores/${storeId}/read`);

  return data.data;
}
