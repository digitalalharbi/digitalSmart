import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import { changeStage, fetchJourney, fetchLeadCentre, fetchLeads } from './api';
import type { LeadListFilter, LeadScope } from './types';

/**
 * The Lead Center's figures.
 *
 * A short stale time: a pipeline is worked by several people at once, and a count that is
 * a minute out of date is a count somebody will act on twice.
 */
export function useLeadCentreQuery(scope: LeadScope, enabled = true) {
  return useQuery({
    queryKey: queryKeys.leads.centre(scope),
    queryFn: () => fetchLeadCentre(scope),
    enabled,
    staleTime: 15_000,
  });
}

export function useLeadsQuery(filter: LeadListFilter, enabled = true) {
  return useQuery({
    queryKey: queryKeys.leads.list(filter),
    queryFn: () => fetchLeads(filter),
    enabled,
    staleTime: 15_000,
  });
}

/**
 * One person's history, fetched only when somebody opens it.
 *
 * Resolved on the server from the records as they are now, so it is never held long: a
 * cached journey is a claim about who somebody is, and that is the last thing to serve stale.
 */
export function useJourneyQuery(leadId: string | null) {
  return useQuery({
    queryKey: queryKeys.leads.journey(leadId ?? ''),
    queryFn: () => fetchJourney(leadId ?? ''),
    enabled: leadId !== null,
    staleTime: 10_000,
  });
}

export function useChangeStageMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: changeStage,
    onSuccess: () => {
      /*
       * Both, because a stage change moves a lead between every count on the page. Nothing
       * is patched in place: the funnel's rates have denominators that change with it, and
       * recomputing them in the browser would be a second implementation of the arithmetic
       * the domain owns.
       */
      void client.invalidateQueries({ queryKey: queryKeys.leads.all() });
    },
  });
}
