import { api } from '@/shared/api/client';
import type {
  ContainerReadiness,
  ConversionDraft,
  ConversionSuggestion,
  DiscoveredProperty,
  EventDefinition,
  MeasurementSource,
  SourceDraft,
  SyncResult,
  TagContainer,
  TrackingHealth,
  TrackingScope,
} from './types';

/** Scope params, omitting the keys the caller left unset so the server applies its defaults. */
function scopeParams(scope: TrackingScope): Record<string, string> {
  return {
    ...(scope.client_id == null ? {} : { client_id: scope.client_id }),
    ...(scope.project_id == null ? {} : { project_id: scope.project_id }),
  };
}

export async function fetchTrackingHealth(scope: TrackingScope): Promise<TrackingHealth> {
  const { data } = await api.get<{ data: TrackingHealth }>('/measurement/health', {
    params: scopeParams(scope),
  });

  return data.data;
}

export async function fetchSources(scope: TrackingScope): Promise<MeasurementSource[]> {
  const { data } = await api.get<{ data: MeasurementSource[] }>('/measurement/sources', {
    params: scopeParams(scope),
  });

  return data.data;
}

export async function createSource(draft: SourceDraft): Promise<MeasurementSource> {
  const { data } = await api.post<{ data: MeasurementSource }>('/measurement/sources', draft);

  return data.data;
}

export async function deleteSource(id: string): Promise<void> {
  await api.delete(`/measurement/sources/${id}`);
}

export async function fetchConversions(scope: TrackingScope): Promise<EventDefinition[]> {
  const { data } = await api.get<{ data: EventDefinition[] }>('/measurement/conversions', {
    params: scopeParams(scope),
  });

  return data.data;
}

export async function createConversion(draft: ConversionDraft): Promise<EventDefinition> {
  const { data } = await api.post<{ data: EventDefinition }>('/measurement/conversions', draft);

  return data.data;
}

export async function updateConversion(
  id: string,
  draft: ConversionDraft,
): Promise<EventDefinition> {
  const { data } = await api.put<{ data: EventDefinition }>(
    `/measurement/conversions/${id}`,
    draft,
  );

  return data.data;
}

export async function deleteConversion(id: string): Promise<void> {
  await api.delete(`/measurement/conversions/${id}`);
}

export async function fetchProperties(provider: string): Promise<DiscoveredProperty[]> {
  const { data } = await api.get<{ data: DiscoveredProperty[] }>(
    `/measurement/providers/${provider}/properties`,
  );

  return data.data;
}

export async function fetchSuggestions(sourceId: string): Promise<ConversionSuggestion[]> {
  const { data } = await api.get<{ data: ConversionSuggestion[] }>(
    `/measurement/sources/${sourceId}/suggestions`,
  );

  return data.data;
}

export async function syncSource(sourceId: string): Promise<SyncResult> {
  const { data } = await api.post<{ data: SyncResult }>(`/measurement/sources/${sourceId}/sync`);

  return data.data;
}

export async function fetchContainers(): Promise<TagContainer[]> {
  const { data } = await api.get<{ data: TagContainer[] }>('/measurement/containers');

  return data.data;
}

export async function fetchReadiness(sourceId: string): Promise<ContainerReadiness> {
  const { data } = await api.get<{ data: ContainerReadiness }>(
    `/measurement/sources/${sourceId}/readiness`,
  );

  return data.data;
}
