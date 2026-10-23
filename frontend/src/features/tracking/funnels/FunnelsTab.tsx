import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useProjectsQuery } from '@/features/projects';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Skeleton } from '@/shared/ui/skeleton';
import { formatNumber, formatPercent } from '@/shared/lib/format';
import {
  useBreakdownQuery,
  useDeleteFunnelMutation,
  useFollowUpQuery,
  useFunnelAnalyticsQuery,
  useFunnelsQuery,
} from './api';
import { formatDuration, periodOfDays } from './duration';
import { FunnelBuilder } from './FunnelBuilder';
import { FunnelView } from './FunnelView';
import { DIMENSIONS, type Dimension, type StoredFunnel } from './types';

const SELECT =
  'border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';

function Stat({ label, value, note }: { label: string; value: string; note?: string | undefined }) {
  return (
    <div className="min-w-0">
      <dt className="text-content-tertiary text-xs">{label}</dt>
      <dd className="text-content text-lg font-semibold tabular-nums">{value}</dd>
      {note ? <p className="text-content-tertiary text-xs">{note}</p> : null}
    </div>
  );
}

/** The Funnels tab: pick a project, see where visitors drop, who follows up, and how traffic breaks down. */
export function FunnelsTab() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canManage = can(PERMISSIONS.trackingManage);
  const [term, setTerm] = useState('');
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [days, setDays] = useState(30);
  const [funnelId, setFunnelId] = useState<string | null>(null);
  const [dimension, setDimension] = useState<Dimension>('platform');
  const [editing, setEditing] = useState<StoredFunnel | 'new' | null>(null);
  const projects = useProjectsQuery({ search: term, per_page: 8 });
  const period = useMemo(() => periodOfDays(days), [days]);
  const projectId = project?.id ?? null;
  const funnels = useFunnelsQuery(projectId);
  const analytics = useFunnelAnalyticsQuery(projectId, funnelId, null, period);
  const followUp = useFollowUpQuery(projectId, period);
  const breakdown = useBreakdownQuery(projectId, dimension, null, period);
  const remove = useDeleteFunnelMutation();
  const stored = funnels.data?.find((f) => f.id === funnelId) ?? null;
  const locale = i18n.language;

  return (
    <div className="flex flex-col gap-5">
      <p className="text-content-secondary max-w-prose text-sm">{t('tracking.funnels.intro')}</p>

      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
        <EntityPicker
          label={t('tracking.links.builder.project')}
          clearLabel={t('actions.clear')}
          value={project}
          onChange={(option) => {
            setProject(option === null ? null : { id: option.id, label: option.label });
            setFunnelId(null);
          }}
          onSearch={setTerm}
          options={(projects.data?.data ?? []).map((p) => ({
            id: p.id,
            label: p.name,
            context: p.client.name,
          }))}
          isLoading={projects.isFetching}
        />
        <label className="flex flex-col gap-1.5 text-sm font-medium">
          {t('tracking.funnels.period')}
          <select className={SELECT} value={days} onChange={(e) => setDays(Number(e.target.value))}>
            {[7, 30, 90].map((d) => (
              <option key={d} value={d}>
                {t('tracking.funnels.lastDays', { count: d })}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1.5 text-sm font-medium">
          {t('tracking.funnels.funnel')}
          <select
            className={SELECT}
            value={funnelId ?? ''}
            onChange={(e) => setFunnelId(e.target.value === '' ? null : e.target.value)}
            disabled={projectId === null}
          >
            <option value="">{t('tracking.funnels.standard')}</option>
            {(funnels.data ?? []).map((f) => (
              <option key={f.id} value={f.id}>
                {f.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {projectId === null ? (
        <EmptyState
          title={t('tracking.funnels.pick.title')}
          description={t('tracking.funnels.pick.body')}
        />
      ) : (
        <>
          {canManage ? (
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="secondary"
                size="sm"
                className="max-md:h-11"
                onClick={() => setEditing('new')}
              >
                {t('tracking.funnels.builder.new')}
              </Button>
              {stored !== null ? (
                <>
                  <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    className="max-md:h-11"
                    onClick={() => setEditing(stored)}
                  >
                    {t('tracking.forms.edit')}
                  </Button>
                  <Button
                    type="button"
                    variant="danger"
                    size="sm"
                    className="max-md:h-11"
                    disabled={remove.isPending}
                    onClick={() => remove.mutate(stored.id, { onSuccess: () => setFunnelId(null) })}
                  >
                    {t('tracking.funnels.builder.delete')}
                  </Button>
                </>
              ) : null}
            </div>
          ) : null}

          {editing !== null ? (
            <FunnelBuilder
              projectId={projectId}
              funnel={editing === 'new' ? null : editing}
              onClose={() => setEditing(null)}
            />
          ) : null}

          <Card className="flex flex-col gap-3 p-4 sm:p-5">
            <h2 className="text-content text-base font-semibold">
              {analytics.data?.name ?? t('tracking.funnels.standard')}
            </h2>
            {analytics.isPending ? (
              <Skeleton className="h-48 w-full" />
            ) : analytics.isError ? (
              <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
            ) : (
              <FunnelView funnel={analytics.data} />
            )}
          </Card>

          <Card className="flex flex-col gap-3 p-4 sm:p-5">
            <h2 className="text-content text-base font-semibold">
              {t('tracking.funnels.followUp.title')}
            </h2>
            {followUp.isPending ? (
              <Skeleton className="h-20 w-full" />
            ) : followUp.isError ? (
              <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
            ) : (
              <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Stat
                  label={t('tracking.funnels.followUp.leads')}
                  value={formatNumber(followUp.data.leads, 'en')}
                />
                <Stat
                  label={t('tracking.funnels.followUp.contactRate')}
                  value={
                    followUp.data.contact_rate === null
                      ? t('tracking.links.notReported')
                      : formatPercent(followUp.data.contact_rate, 'en', 0)
                  }
                />
                <Stat
                  label={t('tracking.funnels.followUp.sla', { minutes: followUp.data.sla_minutes })}
                  value={
                    followUp.data.sla_compliance === null
                      ? t('tracking.links.notReported')
                      : formatPercent(followUp.data.sla_compliance, 'en', 0)
                  }
                />
                <Stat
                  label={t('tracking.funnels.followUp.uncontacted')}
                  value={formatNumber(followUp.data.uncontacted, 'en')}
                  note={t('tracking.funnels.followUp.overdue', {
                    count: followUp.data.overdue_uncontacted,
                  })}
                />
                <Stat
                  label={t('tracking.funnels.followUp.firstAttempt')}
                  value={
                    followUp.data.median_seconds_to_first_attempt === null
                      ? t('tracking.links.notReported')
                      : formatDuration(followUp.data.median_seconds_to_first_attempt, locale)
                  }
                />
                <Stat
                  label={t('tracking.funnels.followUp.firstContact')}
                  value={
                    followUp.data.median_seconds_to_first_contact === null
                      ? t('tracking.links.notReported')
                      : formatDuration(followUp.data.median_seconds_to_first_contact, locale)
                  }
                />
                <Stat
                  label={t('tracking.funnels.followUp.qualified')}
                  value={formatNumber(followUp.data.qualified, 'en')}
                />
              </dl>
            )}
          </Card>

          <Card className="flex flex-col gap-3 p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 className="text-content text-base font-semibold">
                {t('tracking.funnels.breakdown.title')}
              </h2>
              <label className="flex items-center gap-2 text-sm">
                {t('tracking.funnels.breakdown.by')}
                <select
                  className={SELECT}
                  value={dimension}
                  onChange={(e) => setDimension(e.target.value as Dimension)}
                >
                  {DIMENSIONS.map((d) => (
                    <option key={d} value={d}>
                      {t(`tracking.funnels.dimensions.${d}`)}
                    </option>
                  ))}
                </select>
              </label>
            </div>
            {breakdown.isPending ? (
              <Skeleton className="h-24 w-full" />
            ) : breakdown.isError ? (
              <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
            ) : breakdown.data.length === 0 ? (
              <p className="text-content-secondary text-sm">
                {t('tracking.funnels.breakdown.empty')}
              </p>
            ) : (
              <div
                className="overflow-x-auto"
                tabIndex={0}
                role="region"
                aria-label={t('tracking.funnels.breakdown.title')}
              >
                <table className="w-full min-w-[34rem] text-sm">
                  <thead>
                    <tr className="text-content-tertiary text-start text-xs">
                      {[
                        'key',
                        'sessions',
                        'landing',
                        'starts',
                        'submissions',
                        'leads',
                        'qualified',
                        'sales',
                      ].map((c) => (
                        <th key={c} scope="col" className="px-2 py-2 text-start font-medium">
                          {t(`tracking.funnels.breakdown.cols.${c}`)}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {breakdown.data.map((row) => (
                      <tr key={row.key} className="border-line-subtle border-t">
                        <th
                          scope="row"
                          className="max-w-48 truncate px-2 py-2 text-start font-medium"
                          dir="auto"
                        >
                          {row.key}
                        </th>
                        <td className="px-2 py-2 tabular-nums">
                          {formatNumber(row.sessions, 'en')}
                        </td>
                        <td className="px-2 py-2 tabular-nums">
                          {row.landing_views === null ? '—' : formatNumber(row.landing_views, 'en')}
                        </td>
                        <td className="px-2 py-2 tabular-nums">
                          {row.form_starts === null ? '—' : formatNumber(row.form_starts, 'en')}
                        </td>
                        <td className="px-2 py-2 tabular-nums">
                          {formatNumber(row.submissions, 'en')}
                        </td>
                        <td className="px-2 py-2 tabular-nums">{formatNumber(row.leads, 'en')}</td>
                        <td className="px-2 py-2 tabular-nums">
                          {formatNumber(row.qualified, 'en')}
                        </td>
                        <td className="px-2 py-2 tabular-nums">{formatNumber(row.sales, 'en')}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <p className="text-content-tertiary mt-2 text-xs">
                  {t('tracking.funnels.breakdown.dash')}
                </p>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
