import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  createConversion,
  createSource,
  deleteConversion,
  deleteSource,
  fetchContainers,
  fetchProperties,
  fetchReadiness,
  fetchSuggestions,
  fetchTrackingHealth,
  syncSource,
  updateConversion,
} from './api';
import type { ConversionDraft, SourceDraft, TrackingScope } from './types';

/**
 * A client's tracking health.
 *
 * A short stale time, because this is a diagnosis rather than a record: the server recomputes it
 * on every read, and a cached warning about a state somebody has just fixed is exactly the stale
 * alarm the whole design avoids.
 */
export function useTrackingHealthQuery(scope: TrackingScope, enabled = true) {
  return useQuery({
    queryKey: queryKeys.measurement.health(scope),
    queryFn: () => fetchTrackingHealth(scope),
    enabled,
    staleTime: 10_000,
  });
}

/*
 * There is deliberately no `useMeasurementSourcesQuery` or `useConversionsQuery`. Both existed, wrapping
 * `GET /measurement/sources` and `GET /measurement/conversions`, and both were unreachable: the health read
 * already returns the sources and the definitions, judged against each other, and a second copy fetched beside
 * it is a second answer to the same question that can disagree with the first while a page shows both.
 */

/**
 * Properties a connection can read.
 *
 * Only fetched when somebody opens the picker: it is a live call to the provider on the
 * workspace's credentials, not something to make on the chance it will be wanted.
 */
export function usePropertiesQuery(provider: string | null) {
  return useQuery({
    queryKey: queryKeys.measurement.properties(provider ?? 'none'),
    queryFn: () => fetchProperties(provider ?? ''),
    enabled: provider !== null,
    staleTime: 60_000,
  });
}

export function useSuggestionsQuery(sourceId: string | null) {
  return useQuery({
    queryKey: queryKeys.measurement.suggestions(sourceId ?? 'none'),
    queryFn: () => fetchSuggestions(sourceId ?? ''),
    enabled: sourceId !== null,
    staleTime: 60_000,
  });
}

export function useContainersQuery(enabled = true) {
  return useQuery({
    queryKey: queryKeys.measurement.containers(),
    queryFn: fetchContainers,
    enabled,
    staleTime: 60_000,
  });
}

/**
 * One container's published state.
 *
 * Deliberately not folded into the health query. This needs a live call to Tag Manager, and the
 * tracking page has to keep showing the facts it already has when a provider is slow or
 * unreachable.
 */
export function useReadinessQuery(sourceId: string | null) {
  return useQuery({
    queryKey: queryKeys.measurement.readiness(sourceId ?? 'none'),
    queryFn: () => fetchReadiness(sourceId ?? ''),
    enabled: sourceId !== null,
    staleTime: 30_000,
  });
}

/**
 * Everything measurement, invalidated together.
 *
 * Registering a source changes the health diagnosis, defining a conversion changes what the
 * sources list is judged against, and a sync changes both. Invalidating the whole branch is
 * cheaper to reason about than enumerating which pairs interact, and the queries are small.
 */
function useInvalidateMeasurement() {
  const client = useQueryClient();

  return () => client.invalidateQueries({ queryKey: queryKeys.measurement.all() });
}

export function useCreateSourceMutation() {
  const invalidate = useInvalidateMeasurement();

  return useMutation({
    mutationFn: (draft: SourceDraft) => createSource(draft),
    onSuccess: invalidate,
  });
}

export function useDeleteSourceMutation() {
  const invalidate = useInvalidateMeasurement();

  return useMutation({
    mutationFn: (id: string) => deleteSource(id),
    onSuccess: invalidate,
  });
}

export function useSyncSourceMutation() {
  const invalidate = useInvalidateMeasurement();

  return useMutation({
    mutationFn: (id: string) => syncSource(id),
    onSuccess: invalidate,
  });
}

export function useSaveConversionMutation() {
  const invalidate = useInvalidateMeasurement();

  return useMutation({
    mutationFn: ({ id, draft }: { id: string | null; draft: ConversionDraft }) =>
      id === null ? createConversion(draft) : updateConversion(id, draft),
    onSuccess: invalidate,
  });
}

export function useDeleteConversionMutation() {
  const invalidate = useInvalidateMeasurement();

  return useMutation({
    mutationFn: (id: string) => deleteConversion(id),
    onSuccess: invalidate,
  });
}
