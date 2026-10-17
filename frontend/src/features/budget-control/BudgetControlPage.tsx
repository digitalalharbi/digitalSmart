import { PacingHero } from './PacingHero';
import { useUiStore } from '@/shared/store/ui-store';
import { SavedViewsMenu } from '@/features/surface-views';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import { formatMinor, formatPercent } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';

type Risk = { risk: string; label: string; audience: string[] };

type Row = {
  id: string;
  name: string;
  client: { id: string; name: string };
  project: { id: string; name: string } | null;
  currency: string;
  approved_minor: number;
  allocated_minor: number;
  unallocated_minor: number;
  spent_minor: number | null;
  remaining_minor: number | null;
  spend_unavailable_reason: string | null;
  pacing: {
    expected_minor?: number;
    projected_minor: number | null;
    status: string;
    status_label: string;
    variance_percent: number | null;
    utilisation: number | null;
  } | null;
  risks: Risk[];
};

type Payload = {
  data: Row[];
  meta: {
    totals: Array<{
      currency: string;
      approved_minor: number;
      allocated_minor: number;
      spent_minor: number;
      unreported: number;
      at_risk: number;
    }>;
    count: number;
    risks: Array<{ risk: string; label: string; audience: string[] }>;
  };
};

/**
 * Every approved budget in the agency, with pacing and the risks it carries.
 *
 * Finance's surface: nobody opens a project to find out which budgets are in danger. A spend figure the
 * server could not total is shown as exactly that, with the reason — never as a zero, and never as a number
 * that quietly includes a second currency.
 */
export function BudgetControlPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [risk, setRisk] = useState<string | null>(null);
  /* The working context narrows this surface like every other; the topbar chip says so on every screen. */
  const { context } = useUiStore();
  const clientId = context.client?.id ?? null;
  const projectId = context.project?.id ?? null;

  const { data, isPending, isError } = useQuery({
    queryKey: queryKeys.budgetControl.list(risk, clientId, projectId),
    queryFn: async (): Promise<Payload> => {
      const { data: body } = await api.get<Payload>('/budgets/control', {
        params: {
          ...(risk === null ? {} : { risk }),
          ...(clientId === null ? {} : { client_id: clientId }),
          ...(projectId === null ? {} : { project_id: projectId }),
        },
      });

      return body;
    },
    staleTime: 30_000,
  });

  const money = (minor: number | null, currency: string) =>
    minor === null ? '—' : formatMinor(minor, currency, locale);

  return (
    <div className="ds-page">
      <PageHeader
        title={t('budgetControl.title')}
        description={t('budgetControl.subtitle')}
        actions={
          <SavedViewsMenu
            surface="budget_control"
            current={risk === null ? {} : { risk }}
            presets={['overspend', 'underpace', 'near_limit', 'unallocated'].map((key) => ({
              key,
              label: t(`budgetControl.preset.${key}`),
              filters: { risk: key },
            }))}
            onApply={(saved) => setRisk(saved['risk'] ?? null)}
          />
        }
      />

      {isError ? <Alert tone="danger">{t('budgetControl.loadFailed')}</Alert> : null}

      <PacingHero rows={data?.data ?? []} locale={locale} />

      <section
        aria-label={t('budgetControl.totals')}
        className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
      >
        {(data?.meta.totals ?? []).map((total) => (
          <div
            key={total.currency}
            className="bg-surface border-line-subtle rounded-2xl border p-4 shadow-xs"
          >
            <p className="ds-overline text-content-tertiary">{total.currency}</p>
            <dl className="mt-2 grid grid-cols-2 gap-2 text-sm">
              <dt className="text-content-tertiary">{t('budgetControl.approved')}</dt>
              <dd className="ds-numeric text-end">
                {formatMinor(total.approved_minor, total.currency, locale)}
              </dd>
              <dt className="text-content-tertiary">{t('budgetControl.allocated')}</dt>
              <dd className="ds-numeric text-end">
                {formatMinor(total.allocated_minor, total.currency, locale)}
              </dd>
              <dt className="text-content-tertiary">{t('budgetControl.spent')}</dt>
              <dd className="ds-numeric text-end">
                {formatMinor(total.spent_minor, total.currency, locale)}
                {total.unreported > 0 ? <span className="text-content-tertiary"> *</span> : null}
              </dd>
              <dt className="text-content-tertiary">{t('budgetControl.remaining')}</dt>
              <dd
                className={`ds-numeric text-end ${total.approved_minor - total.spent_minor < 0 ? 'text-danger' : ''}`}
              >
                {formatMinor(total.approved_minor - total.spent_minor, total.currency, locale)}
              </dd>
            </dl>
            <div
              role="progressbar"
              aria-label={t('budgetControl.used', { currency: total.currency })}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.min(
                100,
                Math.round((total.spent_minor / Math.max(total.approved_minor, 1)) * 100),
              )}
              className="bg-surface-sunken mt-3 h-2 overflow-hidden rounded-full"
            >
              <div
                className={`h-full rounded-full ${total.spent_minor > total.approved_minor ? 'bg-danger' : 'bg-accent'}`}
                style={{
                  width: `${String(Math.min(100, (total.spent_minor / Math.max(total.approved_minor, 1)) * 100))}%`,
                }}
              />
            </div>
            {total.unreported > 0 ? (
              <p className="text-content-tertiary mt-2 text-xs">
                {t('budgetControl.unreported', { count: total.unreported })}
              </p>
            ) : null}
          </div>
        ))}
      </section>

      <ChoiceGroup label={t('budgetControl.filter')}>
        <div className="flex flex-wrap gap-2">
          <Choice isSelected={risk === null} onSelect={() => setRisk(null)}>
            {t('budgetControl.all')}
          </Choice>
          {(data?.meta.risks ?? []).map((r) => (
            <Choice key={r.risk} isSelected={risk === r.risk} onSelect={() => setRisk(r.risk)}>
              {r.label}
            </Choice>
          ))}
        </div>
      </ChoiceGroup>

      {isPending ? (
        <Skeleton className="h-56 w-full" />
      ) : data === undefined || data.data.length === 0 ? (
        <EmptyState title={t('budgetControl.empty')} description={t('budgetControl.emptyBody')} />
      ) : (
        <DataTable label={t('budgetControl.title')} minWidth="64rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('budgetControl.col.budget')}</TableHeader>
              <TableHeader align="end">{t('budgetControl.approved')}</TableHeader>
              <TableHeader align="end">{t('budgetControl.allocated')}</TableHeader>
              <TableHeader align="end">{t('budgetControl.spent')}</TableHeader>
              <TableHeader align="end">{t('budgetControl.remaining')}</TableHeader>
              <TableHeader>{t('budgetControl.col.pacing')}</TableHeader>
              <TableHeader>{t('budgetControl.col.risks')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {data.data.map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <span className="flex flex-col">
                    <span className="text-content font-medium">{row.name}</span>
                    <span className="text-content-tertiary text-xs">
                      {[row.client.name, row.project?.name].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                </TableCell>
                <TableCell isNumeric align="end" label={t('budgetControl.approved')}>
                  {money(row.approved_minor, row.currency)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('budgetControl.allocated')}>
                  {money(row.allocated_minor, row.currency)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('budgetControl.spent')}>
                  <span title={row.spend_unavailable_reason ?? undefined}>
                    {money(row.spent_minor, row.currency)}
                  </span>
                </TableCell>
                <TableCell isNumeric align="end" label={t('budgetControl.remaining')}>
                  {money(row.remaining_minor, row.currency)}
                </TableCell>
                <TableCell label={t('budgetControl.col.pacing')}>
                  {row.pacing === null ? (
                    <span className="text-content-tertiary text-xs">
                      {row.spend_unavailable_reason}
                    </span>
                  ) : (
                    <span className="flex flex-col text-sm">
                      {row.pacing.status_label}
                      {row.pacing.variance_percent === null ? null : (
                        <span className="text-content-tertiary text-xs">
                          {formatPercent(row.pacing.variance_percent, locale, 0)}
                        </span>
                      )}
                    </span>
                  )}
                </TableCell>
                <TableCell label={t('budgetControl.col.risks')}>
                  <span className="flex flex-wrap gap-1">
                    {row.risks.length === 0 ? (
                      <Badge tone="success">{t('budgetControl.noRisk')}</Badge>
                    ) : (
                      row.risks.map((r) => (
                        <Badge key={r.risk} tone={r.risk === 'overspend' ? 'danger' : 'warning'}>
                          {r.label}
                        </Badge>
                      ))
                    )}
                  </span>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}
    </div>
  );
}
