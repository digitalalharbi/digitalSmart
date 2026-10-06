import { api } from '@/shared/api/client';
import type { Journey, Lead, LeadCentre, LeadListFilter, LeadScope, StageChange } from './types';

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
