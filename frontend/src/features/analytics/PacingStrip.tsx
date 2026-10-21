import { useQuery } from '@tanstack/react-query';
import { PacingHero, type PacedRow } from '@/features/budget-control';
import { api } from '@/shared/api/client';

/**
 * Plan, pace and forecast for the budgets in view, as the Budget Control Center already draws them.
 *
 * The same bars from the same endpoint: a budget's pace is worked out once, on the server, and this page borrows the
 * picture rather than working it out again. It follows the working context — one client, one project, or everything —
 * like every other analytical surface.
 */
export function PacingStrip({
  clientId,
  projectId,
  locale,
}: {
  clientId: string | null;
  projectId: string | null;
  locale: string;
}) {
  const { data } = useQuery({
    queryKey: ['analytics', 'pacing', clientId, projectId],
    queryFn: async (): Promise<PacedRow[]> => {
      const { data: body } = await api.get<{ data: PacedRow[] }>('/budgets/control', {
        params: {
          ...(clientId === null ? {} : { client_id: clientId }),
          ...(projectId === null ? {} : { project_id: projectId }),
        },
      });

      return body.data;
    },
    staleTime: 30_000,
  });

  return data === undefined ? null : <PacingHero rows={data} locale={locale} />;
}
