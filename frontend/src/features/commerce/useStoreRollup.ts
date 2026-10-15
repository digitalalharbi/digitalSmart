import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';

export type StoreRollupRow = {
  store_id: string;
  store_name: string;
  provider: string;
  client_id: string;
  client_name: string;
  currency: string;
  is_active: boolean;
  orders: number;
  revenue_minor: number;
  refunded_minor: number;
  net_revenue_minor: number;
  /** Null when there were no orders — an average of nothing is not zero. */
  average_order_minor: number | null;
  attributed_revenue_minor: number;
  last_synced_at: string | null;
};

export type StoreRollup = {
  revenue_source: string;
  from: string;
  until: string;
  stores: StoreRollupRow[];
};

export function useStoreRollup(params: {
  revenueSource: string;
  days: number;
  clientId: string | null;
}) {
  return useQuery({
    queryKey: queryKeys.commerce.rollup(params),
    queryFn: async (): Promise<StoreRollup> => {
      const until = new Date();
      const from = new Date(until.getTime() - (params.days - 1) * 86_400_000);
      const day = (d: Date) => d.toISOString().slice(0, 10);
      const { data } = await api.get<{ data: StoreRollup }>('/commerce/rollup', {
        params: {
          revenue_source: params.revenueSource,
          from: day(from),
          until: day(until),
          ...(params.clientId === null ? {} : { client_id: params.clientId }),
        },
      });

      return data.data;
    },
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}
