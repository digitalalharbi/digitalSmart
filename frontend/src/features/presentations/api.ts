import { api } from '@/shared/api/client';
import type { Deck, DeckKindValue, PresentationVocabulary } from './types';

export async function fetchPresentationVocabulary(): Promise<PresentationVocabulary> {
  const { data } = await api.get<{ data: PresentationVocabulary }>('/presentations/vocabulary');

  return data.data;
}

export async function fetchDeck(input: {
  kind: DeckKindValue;
  clientId: string | null;
  budgetId: string | null;
}): Promise<Deck> {
  const { data } = await api.get<{ data: Deck }>(`/presentations/${input.kind}`, {
    params: {
      ...(input.clientId === null ? {} : { client_id: input.clientId }),
      ...(input.budgetId === null ? {} : { budget_id: input.budgetId }),
    },
  });

  return data.data;
}

/**
 * The PowerPoint download URL.
 *
 * A plain link, so the browser handles the download rather than JavaScript holding a deck in memory to hand it
 * back to itself.
 */
export function pptxUrl(input: {
  kind: DeckKindValue;
  clientId: string | null;
  budgetId: string | null;
}): string {
  const params = new URLSearchParams();

  if (input.clientId !== null) {
    params.set('client_id', input.clientId);
  }

  if (input.budgetId !== null) {
    params.set('budget_id', input.budgetId);
  }

  return `/api/presentations/${input.kind}/pptx?${params.toString()}`;
}
