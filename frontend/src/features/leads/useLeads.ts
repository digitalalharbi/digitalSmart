import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { queryKeys } from '@/shared/api/query-keys';
import {
  addLeadNote,
  assignLead,
  changeStage,
  fetchJourney,
  fetchLeadCentre,
  fetchLeadDuplicates,
  fetchLeadHistory,
  fetchLeadOwners,
  fetchLeads,
  mergeLeads,
  setLeadValue,
} from './api';
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

/**
 * The leads that may be the same person as this one.
 *
 * Not cached in any meaningful sense. The list exists to be acted on once, irreversibly, and a row held from a
 * minute ago may already have been merged away by a colleague working the same pipeline.
 */
export function useLeadDuplicatesQuery(leadId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.leads.duplicates(leadId ?? ''),
    queryFn: () => fetchLeadDuplicates(leadId ?? ''),
    enabled: enabled && leadId !== null,
    staleTime: 0,
  });
}

export function useMergeLeadsMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: mergeLeads,
    onSuccess: () => {
      /*
       * Everything. A merge removes a lead from every list and every count on the page, changes the surviving
       * record's age and may change which campaign it is attributed to — there is no subset of this page that
       * is still correct afterwards.
       */
      void client.invalidateQueries({ queryKey: queryKeys.leads.all() });
    },
  });
}

/**
 * Who this lead can be put in the name of.
 *
 * Held for a minute: the answer changes when somebody is added to a project, which is rare, and a picker that
 * refetched on every selection would ask the server a question it already knows the answer to.
 */
export function useLeadOwnersQuery(leadId: string | null, enabled = true) {
  return useQuery({
    queryKey: queryKeys.leads.owners(leadId ?? ''),
    queryFn: () => fetchLeadOwners(leadId ?? ''),
    enabled: enabled && leadId !== null,
    staleTime: 60_000,
  });
}

export function useAssignLeadMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: assignLead,
    /*
     * Everything under `leads`, which includes the history: an assignment is an event on it, and the owner
     * appears in the pipeline list too.
     */
    onSuccess: () => void client.invalidateQueries({ queryKey: queryKeys.leads.all() }),
  });
}

export function useSetLeadValueMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: setLeadValue,
    /*
     * Everything, because the value feeds the funnel's won-value total as well as the row. Recomputing that
     * total in the browser would be a second implementation of arithmetic the domain owns.
     */
    onSuccess: () => void client.invalidateQueries({ queryKey: queryKeys.leads.all() }),
  });
}

/**
 * What has happened to this lead.
 *
 * The read half of everything the pipeline writes. Without it a note would be a write nobody can see, which is
 * a worse surface than having no notes at all.
 */
export function useLeadHistoryQuery(leadId: string | null) {
  return useQuery({
    queryKey: queryKeys.leads.history(leadId ?? ''),
    queryFn: () => fetchLeadHistory(leadId ?? ''),
    enabled: leadId !== null,
    staleTime: 10_000,
  });
}

export function useAddLeadNoteMutation() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: addLeadNote,
    /*
     * The history, which is where a note becomes visible. Not the lists: a note changes no figure on them, and
     * refetching a pipeline because somebody wrote a sentence is work nobody asked for.
     */
    onSuccess: (lead) =>
      void client.invalidateQueries({ queryKey: queryKeys.leads.history(lead.id) }),
  });
}
