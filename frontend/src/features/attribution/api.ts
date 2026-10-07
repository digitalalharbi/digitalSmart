import { api } from '@/shared/api/client';
import type {
  AttributionAnswer,
  AttributionComparison,
  AttributionModelInfo,
  AttributionParams,
  ComparisonParams,
} from './types';

/** Scope params, omitting what the caller left unset so the server applies its own defaults. */
function params(query: Record<string, string | number | null | undefined>): Record<string, string> {
  const result: Record<string, string> = {};

  for (const [key, value] of Object.entries(query)) {
    if (value !== null && value !== undefined && value !== '') {
      result[key] = String(value);
    }
  }

  return result;
}

export async function fetchAttributionModels(): Promise<AttributionModelInfo[]> {
  const { data } = await api.get<{ data: AttributionModelInfo[] }>('/attribution/models');

  return data.data;
}

export async function fetchAttribution(query: AttributionParams): Promise<AttributionAnswer> {
  const { data } = await api.get<{ data: AttributionAnswer }>('/attribution', {
    params: params({ ...query }),
  });

  return data.data;
}

export async function fetchComparison(query: ComparisonParams): Promise<AttributionComparison> {
  const { data } = await api.get<{ data: AttributionComparison }>('/attribution/comparison', {
    params: params({ ...query }),
  });

  return data.data;
}
