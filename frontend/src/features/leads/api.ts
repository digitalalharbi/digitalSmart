import { api } from '@/shared/api/client';
import type {
  Journey,
  Lead,
  LeadAssignment,
  LeadCentre,
  LeadDuplicate,
  LeadHistoryEntry,
  LeadListFilter,
  LeadMerge,
  LeadNote,
  LeadOwnerCandidate,
  LeadScope,
  LeadValue,
  StageChange,
} from './types';

export async function fetchLeadCentre(scope: LeadScope): Promise<LeadCentre> {
  const { data } = await api.get<{ data: LeadCentre }>('/leads/centre', {
    params: {
      ...(scope.client_id == null ? {} : { client_id: scope.client_id }),
      ...(scope.days == null ? {} : { days: scope.days }),
    },
  });

  return data.data;
}

export async function fetchLeads(filter: LeadListFilter): Promise<Lead[]> {
  const { data } = await api.get<{ data: Lead[] }>('/leads', {
    params: {
      ...(filter.client_id == null ? {} : { client_id: filter.client_id }),
      ...(filter.stage === undefined || filter.stage.length === 0 ? {} : { stage: filter.stage }),
      ...(filter.limit === undefined ? {} : { limit: filter.limit }),
    },
  });

  return data.data;
}

export async function changeStage(change: StageChange): Promise<Lead> {
  const { data } = await api.post<{ data: Lead }>(`/leads/${change.leadId}/stage`, {
    stage: change.stage,
    ...(change.reason === undefined ? {} : { reason: change.reason }),
    ...(change.note === undefined ? {} : { note: change.note }),
  });

  return data.data;
}

export async function fetchJourney(leadId: string): Promise<Journey> {
  const { data } = await api.get<{ data: Journey }>(`/leads/${leadId}/journey`);

  return data.data;
}

export async function fetchLeadDuplicates(leadId: string): Promise<LeadDuplicate[]> {
  const { data } = await api.get<{ data: LeadDuplicate[] }>(`/leads/${leadId}/duplicates`);

  return data.data;
}

/**
 * Put two leads' histories on one record.
 *
 * `stage` is required and has to be a stage one of the two leads is already in — the server refuses a third, so
 * the interface offers only those two rather than sending a request it knows will fail.
 */
export async function mergeLeads(merge: LeadMerge): Promise<Lead> {
  const { data } = await api.post<{ data: Lead }>(`/leads/${merge.leadId}/merge`, {
    lead_id: merge.otherLeadId,
    stage: merge.stage,
    ...(merge.note === undefined ? {} : { note: merge.note }),
  });

  return data.data;
}

export async function fetchLeadOwners(leadId: string): Promise<LeadOwnerCandidate[]> {
  const { data } = await api.get<{ data: LeadOwnerCandidate[] }>(`/leads/${leadId}/owners`);

  return data.data;
}

/**
 * Put a lead in somebody's name, or in nobody's.
 *
 * The server refuses an owner who could not open the lead, so the picker is built from `fetchLeadOwners` rather
 * than from the member list: every name in it is one the server will accept.
 */
export async function assignLead(assignment: LeadAssignment): Promise<Lead> {
  const { data } = await api.post<{ data: Lead }>(`/leads/${assignment.leadId}/owner`, {
    owner_id: assignment.ownerId ?? '',
  });

  return data.data;
}

export async function setLeadValue(value: LeadValue): Promise<Lead> {
  const { data } = await api.post<{ data: Lead }>(`/leads/${value.leadId}/value`, {
    value_minor: value.valueMinor,
    ...(value.currency === null ? {} : { currency: value.currency }),
  });

  return data.data;
}

export async function addLeadNote(note: LeadNote): Promise<Lead> {
  const { data } = await api.post<{ data: Lead }>(`/leads/${note.leadId}/notes`, {
    note: note.note,
  });

  return data.data;
}

export async function fetchLeadHistory(leadId: string): Promise<LeadHistoryEntry[]> {
  const { data } = await api.get<{ data: LeadHistoryEntry[] }>(`/leads/${leadId}/history`);

  return data.data;
}
