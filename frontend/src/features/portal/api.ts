import { api, ApiError } from '@/shared/api/client';
import type { RenderedReport, ReportSummary } from '@/features/reports';
import type { RevenueSourceValue, StoreAnalytics } from '@/features/commerce';
import type { TrackingHealth } from '@/features/tracking';
import type {
  PortalDetail,
  PortalEntry,
  PortalModuleValue,
  PortalPerformance,
  PortalSummary,
  PortalVocabulary,
} from './types';

/* ----------------------------------------------------------------- agency ---- */

export async function fetchPortalVocabulary(): Promise<PortalVocabulary> {
  const { data } = await api.get<{ data: PortalVocabulary }>('/portals/vocabulary');

  return data.data;
}

export async function fetchPortals(): Promise<PortalSummary[]> {
  const { data } = await api.get<{ data: PortalSummary[] }>('/portals');

  return data.data;
}

export async function fetchPortal(clientId: string): Promise<PortalDetail> {
  const { data } = await api.get<{ data: PortalDetail }>(`/portals/${clientId}`);

  return data.data;
}

export async function enablePortal(clientId: string): Promise<PortalDetail> {
  const { data } = await api.post<{ data: PortalDetail }>(`/portals/${clientId}/enable`);

  return data.data;
}

export async function suspendPortal(input: {
  clientId: string;
  reason: string;
}): Promise<PortalDetail> {
  const { data } = await api.post<{ data: PortalDetail }>(`/portals/${input.clientId}/suspend`, {
    reason: input.reason,
  });

  return data.data;
}

export async function resumePortal(clientId: string): Promise<PortalDetail> {
  const { data } = await api.post<{ data: PortalDetail }>(`/portals/${clientId}/resume`);

  return data.data;
}

/**
 * The whole set, not a toggle.
 *
 * The question being answered is "what can this client see", and a request that changed one module at a time can
 * leave that answer half-applied. The server records one audit entry per module that actually changed.
 */
export async function setPortalModules(input: {
  clientId: string;
  modules: PortalModuleValue[];
}): Promise<PortalDetail> {
  const { data } = await api.put<{ data: PortalDetail }>(`/portals/${input.clientId}/modules`, {
    modules: input.modules,
  });

  return data.data;
}

/* ----------------------------------------------------------------- client ---- */

export async function fetchMyPortals(): Promise<PortalEntry[]> {
  const { data } = await api.get<{ data: PortalEntry[] }>('/portal');

  return data.data;
}

export async function fetchPortalPerformance(clientId: string): Promise<PortalPerformance> {
  const { data } = await api.get<{ data: PortalPerformance }>(`/portal/${clientId}/performance`);

  return data.data;
}

export async function fetchPortalReports(clientId: string): Promise<ReportSummary[]> {
  const { data } = await api.get<{ data: ReportSummary[] }>(`/portal/${clientId}/reports`);

  return data.data;
}

export async function fetchPortalReport(input: {
  clientId: string;
  reportId: string;
}): Promise<RenderedReport> {
  const { data } = await api.get<{ data: RenderedReport }>(
    `/portal/${input.clientId}/reports/${input.reportId}`,
  );

  return data.data;
}

/** What the server will accept as the authority counting a client's money. */
export type AvailableRevenueSource = {
  revenue_source: RevenueSourceValue;
  label: string;
  /** True only for a store or a CRM. Everything else is a measurement of, or a claim about, money. */
  is_invoiced: boolean;
};

/**
 * Revenue, which cannot be read without naming who is counting.
 *
 * Passing no source is a legitimate first call: the server answers 422 with the sources it will accept, which is
 * how the panel builds its chooser without a second endpoint and without a list of its own to drift.
 */
export async function fetchPortalRevenue(input: {
  clientId: string;
  revenueSource: RevenueSourceValue | null;
}): Promise<{ analytics: StoreAnalytics | null; available: AvailableRevenueSource[] }> {
  try {
    const { data } = await api.get<{ data: StoreAnalytics }>(
      `/portal/${input.clientId}/revenue`,
      input.revenueSource === null ? {} : { params: { revenue_source: input.revenueSource } },
    );

    return { analytics: data.data, available: [] };
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      /* The refusal carries the choice. Nothing is shown until somebody makes it. */
      const available = (error.payload?.['available'] ?? []) as AvailableRevenueSource[];

      return { analytics: null, available };
    }

    throw error;
  }
}

export async function fetchPortalTracking(clientId: string): Promise<TrackingHealth> {
  const { data } = await api.get<{ data: TrackingHealth }>(`/portal/${clientId}/tracking`);

  return data.data;
}
