import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Target } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { api } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { ChartCard } from '@/shared/ui/viz';

type Rules = {
  primary_kpi: string;
  target_cpa_minor: number | null;
  target_cpl_minor: number | null;
  target_roas: number | null;
  min_spend_minor: number | null;
  max_daily_budget_minor: number | null;
  max_budget_change_pct: number;
  revenue_authority: string;
  sources: Record<string, 'project' | 'client' | 'agency_default'>;
};

const FIELDS = [
  { key: 'target_cpa_minor', money: true },
  { key: 'target_roas', money: false },
  { key: 'min_spend_minor', money: true },
  { key: 'max_daily_budget_minor', money: true },
  { key: 'max_budget_change_pct', money: false },
] as const;

/**
 * The project's targets and limits — what the agents judge against, and what no proposal may cross.
 *
 * Each figure says where it comes from (this project, its client, or the agency default), so nobody has to guess
 * whose target a recommendation used. Money is entered as a person says it (major units) and stored in minor units.
 */
export function TargetsCard({ projectId, currency }: { projectId: string; currency: string }) {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const client = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<Record<string, string>>({});

  const rules = useQuery({
    queryKey: ['operating-rules', projectId] as const,
    queryFn: async (): Promise<Rules> => {
      const { data } = await api.get<{ data: { effective: Rules } }>('/operating-rules', {
        params: { project_id: projectId },
      });

      return data.data.effective;
    },
  });

  const save = useMutation({
    mutationFn: (values: Record<string, number | null>) =>
      api.put('/operating-rules', { project_id: projectId, ...values }),
    onSuccess: () => {
      setEditing(false);
      void client.invalidateQueries({ queryKey: ['operating-rules', projectId] });
    },
  });

  const show = (key: (typeof FIELDS)[number]['key'], money: boolean): string => {
    const value = rules.data?.[key] ?? null;

    if (value === null) {
      return '—';
    }

    if (key === 'max_budget_change_pct') {
      return `${String(value)}%`;
    }

    if (key === 'target_roas') {
      return `${String(value)}×`;
    }

    return money
      ? new Intl.NumberFormat(i18n.language, {
          style: 'currency',
          currency,
          maximumFractionDigits: 2,
        }).format(Number(value) / 100)
      : String(value);
  };

  return (
    <ChartCard
      title={t('targets.title')}
      action={
        can(PERMISSIONS.budgetsManage) && !editing ? (
          <Button
            size="sm"
            variant="secondary"
            onClick={() => {
              setDraft(
                Object.fromEntries(
                  FIELDS.map(({ key, money }) => {
                    const value = rules.data?.[key] ?? null;

                    return [key, value === null ? '' : String(money ? Number(value) / 100 : value)];
                  }),
                ),
              );
              setEditing(true);
            }}
          >
            {t('targets.edit')}
          </Button>
        ) : undefined
      }
    >
      {save.isError ? <Alert tone="danger">{t('targets.failed')}</Alert> : null}
      {editing ? (
        <form
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault();
            const values: Record<string, number | null> = {};

            for (const { key, money } of FIELDS) {
              const raw = (draft[key] ?? '').trim();
              values[key] = raw === '' ? null : money ? Math.round(Number(raw) * 100) : Number(raw);
            }

            save.mutate(values);
          }}
        >
          {FIELDS.map(({ key }) => (
            <label key={key} className="flex flex-col gap-1 text-sm">
              <span className="text-content-secondary text-xs font-medium">
                {t(`targets.field.${key}`)}
              </span>
              <Input
                inputMode="decimal"
                value={draft[key] ?? ''}
                onChange={(event) => setDraft((d) => ({ ...d, [key]: event.target.value }))}
              />
            </label>
          ))}
          <div className="flex gap-2 sm:col-span-2">
            <Button type="submit" disabled={save.isPending}>
              {t('targets.save')}
            </Button>
            <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
              {t('targets.cancel')}
            </Button>
          </div>
        </form>
      ) : (
        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {FIELDS.map(({ key, money }) => (
            <li key={key} className="bg-surface-sunken flex flex-col gap-1 rounded-2xl p-4">
              <span className="text-content-tertiary flex items-center gap-1.5 text-xs">
                <Target aria-hidden className="size-3.5" />
                {t(`targets.field.${key}`)}
              </span>
              <span className="ds-numeric text-content text-lg font-semibold">
                {show(key, money)}
              </span>
              <span className="text-content-tertiary text-xs">
                {t(`targets.source.${rules.data?.sources[key] ?? 'agency_default'}`)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </ChartCard>
  );
}
