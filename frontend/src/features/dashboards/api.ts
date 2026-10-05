import { api } from '@/shared/api/client';
import type { DashboardDraft, DashboardPayload, SavedViewInput, SavedViewPayload } from './types';

/** The wire shape of a draft: widgets lose their editing-session keys. */
function toRequest(draft: DashboardDraft): Record<string, unknown> {
  return {
    name: draft.name,
    description: draft.description,
    visibility: draft.visibility,
    project_id: draft.project_id,
    filters: draft.filters,
    is_default: draft.is_default,
    widgets: draft.widgets.map((widget) => ({
      title: widget.title,
      type: widget.type,
      metrics: widget.metrics,
      dimension: widget.dimension,
      filter_overrides: widget.filter_overrides,
      column: widget.column,
      row: widget.row,
      width: widget.width,
      height: widget.height,
      options: widget.options,
    })),
  };
}

export async function fetchDashboards(): Promise<DashboardPayload[]> {
  const { data } = await api.get<{ data: DashboardPayload[] }>('/dashboards');

  return data.data;
}

export async function fetchDashboard(id: string): Promise<DashboardPayload> {
  const { data } = await api.get<{ data: DashboardPayload }>(`/dashboards/${id}`);

  return data.data;
}

export async function createDashboard(draft: DashboardDraft): Promise<DashboardPayload> {
  const { data } = await api.post<{ data: DashboardPayload }>('/dashboards', toRequest(draft));

  return data.data;
}

export async function updateDashboard(
  id: string,
  draft: DashboardDraft,
): Promise<DashboardPayload> {
  const { data } = await api.put<{ data: DashboardPayload }>(`/dashboards/${id}`, toRequest(draft));

  return data.data;
}

export async function deleteDashboard(id: string): Promise<void> {
  await api.delete(`/dashboards/${id}`);
}

export async function duplicateDashboard(id: string, name?: string): Promise<DashboardPayload> {
  const { data } = await api.post<{ data: DashboardPayload }>(
    `/dashboards/${id}/duplicate`,
    name === undefined ? {} : { name },
  );

  return data.data;
}

export async function fetchSavedViews(): Promise<SavedViewPayload[]> {
  const { data } = await api.get<{ data: SavedViewPayload[] }>('/analytics/views');

  return data.data;
}

export async function createSavedView(input: SavedViewInput): Promise<SavedViewPayload> {
  const { data } = await api.post<{ data: SavedViewPayload }>('/analytics/views', input);

  return data.data;
}

export async function updateSavedView(
  id: string,
  input: SavedViewInput,
): Promise<SavedViewPayload> {
  const { data } = await api.put<{ data: SavedViewPayload }>(`/analytics/views/${id}`, input);

  return data.data;
}

export async function deleteSavedView(id: string): Promise<void> {
  await api.delete(`/analytics/views/${id}`);
}

/**
 * Record that a view was opened.
 *
 * Fire-and-forget: the ordering it feeds is a convenience, and a failed write must
 * never stop the reader from seeing the view they just asked for.
 */
export async function markSavedViewOpened(id: string): Promise<void> {
  await api.post(`/analytics/views/${id}/opened`).catch(() => undefined);
}
