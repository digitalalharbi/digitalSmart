import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { formatMinor, formatNumber } from '@/shared/lib/format';
import { DonutChart, HealthRing } from '@/shared/ui/viz';
import { ActivityFeed } from '@/shared/ui/activity-feed';
import { Badge } from '@/shared/ui/badge';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { HealthPill } from '@/shared/ui/health';
import type { HealthState } from '@/shared/ui/health.model';
import { Skeleton } from '@/shared/ui/skeleton';
import type { UseQueryResult } from '@tanstack/react-query';
import type {
  ActivityRow,
  ApprovalLite,
  AttentionLite,
  BudgetRow,
  CampaignList,
  CampaignRow,
  CreativeLite,
  LeadFunnel,
  ReportLite,
  TaskLite,
} from './data';

const HEALTH: Record<CampaignRow['health'], HealthState> = {
  healthy: 'healthy',
  watch: 'watch',
  needs_attention: 'attention',
  critical: 'critical',
  inactive: 'inactive',
  insufficient_data: 'unknown',
};

/**
 * One tab's body: skeleton while it loads, a plain sentence when the read was refused or failed, and the
 * content otherwise. A tab that cannot be read says so instead of looking empty — "nothing here" and "you may
 * not see this" are different statements.
 */
export function Section<T>({
  query,
  title,
  to,
  openLabel,
  children,
}: {
  query: UseQueryResult<T>;
  title: string;
  to?: string;
  openLabel?: string;
  children: (data: T) => ReactNode;
}) {
  const { t } = useTranslation();

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle>{title}</CardTitle>
        {to === undefined ? null : (
          <Link to={to} className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
            {openLabel ?? t('home.openAll')}
          </Link>
        )}
      </CardHeader>
      <CardContent>
        {query.isPending && query.fetchStatus !== 'idle' ? (
          <Skeleton className="h-24" />
        ) : query.isError || query.data === undefined ? (
          <p className="text-content-tertiary text-sm">{t('workspace.unavailable')}</p>
        ) : (
          children(query.data)
        )}
      </CardContent>
    </Card>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="text-content-tertiary text-sm">{text}</p>;
}

function Row({ children }: { children: ReactNode }) {
  return (
    <li className="border-line-subtle flex flex-col gap-1 border-b py-2.5 last:border-0">
      {children}
    </li>
  );
}

export function CampaignsList({ data, locale }: { data: CampaignList; locale: string }) {
  const { t } = useTranslation();

  if (data.rows.length === 0) {
    return <Empty text={t('workspace.none.campaigns')} />;
  }

  return (
    <ul className="flex flex-col">
      {data.rows.map((row) => (
        <Row key={row.id}>
          <div className="flex items-center justify-between gap-3">
            <Link
              to={`/campaigns/${row.id}`}
              className="text-content min-w-0 truncate text-sm font-medium hover:underline"
              dir="auto"
            >
              {row.name}
            </Link>
            <HealthPill state={HEALTH[row.health]} />
          </div>
          <p className="text-content-secondary text-xs">{row.health_reason}</p>
          {row.spend_minor !== null && row.currency !== null ? (
            <p className="text-content-tertiary ds-numeric text-xs">
              {formatMinor(row.spend_minor, row.currency, locale)}
            </p>
          ) : null}
        </Row>
      ))}
    </ul>
  );
}

export function BudgetList({
  rows,
  scope,
  locale,
}: {
  rows: BudgetRow[];
  scope: 'project' | 'client';
  locale: string;
}) {
  const { t } = useTranslation();

  if (rows.length === 0) {
    return <Empty text={t('workspace.none.budget')} />;
  }

  return (
    <>
      {scope === 'client' ? (
        <p className="text-content-tertiary mb-2 text-xs">{t('workspace.clientBudget')}</p>
      ) : null}
      <ul className="flex flex-col">
        {rows.map((row) => {
          const share =
            row.spent_minor === null || row.approved_minor === 0
              ? null
              : Math.min(100, Math.round((row.spent_minor / row.approved_minor) * 100));

          return (
            <Row key={row.id}>
              <div className="flex items-center justify-between gap-3 text-sm">
                <span className="text-content min-w-0 truncate font-medium" dir="auto">
                  {row.name}
                </span>
                <span className="ds-numeric text-content-secondary shrink-0">
                  {row.spent_minor === null
                    ? '—'
                    : formatMinor(row.spent_minor, row.currency, locale)}{' '}
                  / {formatMinor(row.approved_minor, row.currency, locale)}
                </span>
              </div>
              {share === null ? null : (
                <div
                  role="img"
                  aria-label={t('workspace.spentShare', { percent: share })}
                  className="bg-surface-sunken h-1.5 overflow-hidden rounded-full"
                >
                  <div className="bg-accent h-full rounded-full" style={{ width: `${share}%` }} />
                </div>
              )}
              <span className="flex flex-wrap gap-1">
                {row.pacing === null ? null : <Badge>{row.pacing.status_label}</Badge>}
                {row.risks.map((risk) => (
                  <Badge key={risk.risk} tone="warning">
                    {risk.label}
                  </Badge>
                ))}
              </span>
            </Row>
          );
        })}
      </ul>
    </>
  );
}

export function LeadsSummary({ funnel }: { funnel: LeadFunnel }) {
  const { t } = useTranslation();

  if (funnel.total === 0) {
    return <Empty text={t('workspace.none.leads')} />;
  }

  return (
    <dl className="grid grid-cols-3 gap-3">
      {(
        [
          ['total', funnel.total],
          ['open', funnel.open],
          ['won', funnel.won],
        ] as const
      ).map(([key, value]) => (
        <div key={key} className="bg-surface-sunken rounded-xl p-3">
          <dt className="text-content-tertiary text-xs">{t(`workspace.lead.${key}`)}</dt>
          <dd className="ds-figure text-xl">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

export function TasksList({
  data,
  locale,
}: {
  data: { open: TaskLite[]; overdue: number };
  locale: string;
}) {
  const { t } = useTranslation();

  if (data.open.length === 0) {
    return <Empty text={t('workspace.none.tasks')} />;
  }

  const date = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' });

  return (
    <ul className="flex flex-col">
      {data.open.slice(0, 10).map((task) => (
        <Row key={task.id}>
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="text-content min-w-0 truncate font-medium" dir="auto">
              {task.title}
            </span>
            {task.is_overdue ? <Badge tone="danger">{t('workspace.overdue')}</Badge> : null}
          </div>
          <p className="text-content-tertiary text-xs">
            {[
              task.state_label,
              task.assignee_name ?? t('attention.unassigned'),
              task.due_on === null ? null : date.format(new Date(task.due_on)),
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </Row>
      ))}
    </ul>
  );
}

export function CreativeList({ rows }: { rows: CreativeLite[] }) {
  const { t } = useTranslation();

  if (rows.length === 0) {
    return <Empty text={t('workspace.none.creative')} />;
  }

  return (
    <ul className="grid gap-3 sm:grid-cols-2">
      {rows.slice(0, 12).map((row) => (
        <li key={row.id} className="bg-surface-sunken flex flex-col gap-1 rounded-xl p-3">
          <span className="text-content truncate text-sm font-medium" dir="auto">
            {row.title}
          </span>
          <span className="flex flex-wrap gap-1">
            <Badge tone={row.is_overdue ? 'danger' : 'neutral'}>{row.state_label}</Badge>
            <Badge>{row.kind_label}</Badge>
          </span>
          <span className="text-content-tertiary text-xs">
            {row.owner_name ?? t('attention.unassigned')}
          </span>
        </li>
      ))}
    </ul>
  );
}

export function ApprovalsList({ rows }: { rows: ApprovalLite[] }) {
  const { t } = useTranslation();

  if (rows.length === 0) {
    return <Empty text={t('workspace.none.approvals')} />;
  }

  return (
    <ul className="flex flex-col">
      {rows.map((row) => (
        <Row key={row.id}>
          <span className="text-content text-sm font-medium" dir="auto">
            {row.title}
          </span>
          <p className="text-content-tertiary text-xs">
            {[
              row.kind_label,
              row.requested_by_name,
              row.waiting_days === null
                ? null
                : t('workspace.waitingDays', { count: row.waiting_days }),
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </Row>
      ))}
    </ul>
  );
}

export function ReportsList({ rows }: { rows: ReportLite[] }) {
  const { t } = useTranslation();

  if (rows.length === 0) {
    return <Empty text={t('workspace.none.reports')} />;
  }

  return (
    <ul className="flex flex-col">
      {rows.map((row) => (
        <Row key={row.id}>
          <div className="flex items-center justify-between gap-3 text-sm">
            <Link
              to={`/reports?report=${row.id}`}
              className="text-content min-w-0 truncate font-medium hover:underline"
              dir="auto"
            >
              {row.name}
            </Link>
            {row.active_share_count > 0 ? (
              <Badge tone="info">{t('workspace.shared', { count: row.active_share_count })}</Badge>
            ) : null}
          </div>
          <p className="text-content-tertiary text-xs">{row.window_label}</p>
        </Row>
      ))}
    </ul>
  );
}

export function Activity({ query }: { query: UseQueryResult<ActivityRow[]> }) {
  const { t } = useTranslation();

  if (query.isError) {
    return <Empty text={t('workspace.unavailable')} />;
  }

  return (
    <ActivityFeed
      isPending={query.isPending}
      entries={(query.data ?? []).map((row) => ({
        id: row.id,
        actor: row.actor,
        summary: row.sentence,
        at: row.at,
      }))}
    />
  );
}

/** The next things to do, worst first, each one a link to where it is done. */
export function NextActions({ items }: { items: AttentionLite[] }) {
  const { t } = useTranslation();

  if (items.length === 0) {
    return (
      <EmptyState title={t('workspace.nothingNext')} description={t('workspace.nothingNextHint')} />
    );
  }

  return (
    <ul className="flex flex-col">
      {items.slice(0, 6).map((item) => (
        <Row key={item.id}>
          <div className="flex items-start justify-between gap-3">
            <span className="text-content text-sm" dir="auto">
              {item.message}
            </span>
            <Badge tone={item.severity === 'critical' ? 'danger' : 'warning'}>
              {t(`workspace.severity.${item.severity}`)}
            </Badge>
          </div>
          <Link
            to={item.action.path}
            className="text-content-brand text-xs font-medium hover:underline"
          >
            {t('workspace.act')}
          </Link>
        </Row>
      ))}
    </ul>
  );
}

/**
 * Three pictures at the top of a project: how its campaigns are doing, how much of the budget is spent, and what is
 * waiting on the team. Each is a ring or a figure — the words are in the lists below, for anyone who wants them.
 */
export function OverviewRings({
  health,
  spent,
  approved,
  currency,
  share,
  openTasks,
  overdue,
  waiting,
  locale,
}: {
  health: Record<string, number> | null;
  spent: number | null;
  approved: number;
  currency: string;
  share: number | null;
  openTasks: number | null;
  overdue: number;
  waiting: number | null;
  locale: string;
}) {
  const { t } = useTranslation();
  const counts = {
    healthy: health?.['healthy'] ?? 0,
    watch: health?.['watch'] ?? 0,
    needs_attention: health?.['needs_attention'] ?? 0,
    critical: health?.['critical'] ?? 0,
    inactive: health?.['inactive'] ?? 0,
    insufficient_data: health?.['insufficient_data'] ?? 0,
  };

  return (
    <div className="grid gap-4 md:grid-cols-3">
      <Card>
        <CardHeader className="pb-2">
          <CardTitle>{t('workspace.campaignHealth')}</CardTitle>
        </CardHeader>
        <CardContent className="flex items-center gap-4">
          {health === null ? (
            <Skeleton className="h-24 w-full" />
          ) : (
            <HealthRing
              counts={counts}
              size={104}
              thickness={13}
              label={t('workspace.campaignHealth')}
            />
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle>{t('workspace.tab.budget')}</CardTitle>
        </CardHeader>
        <CardContent>
          {approved === 0 ? (
            <p className="text-content-tertiary text-sm">—</p>
          ) : (
            <DonutChart
              size={104}
              thickness={13}
              label={t('workspace.tab.budget')}
              format={(v) => formatMinor(v, currency, locale)}
              centre={{ value: share === null ? '—' : `${String(share)}%` }}
              segments={[
                {
                  key: 'spent',
                  label: t('workspace.spentLabel'),
                  value: spent ?? 0,
                  colour: 'var(--ds-accent)',
                },
                {
                  key: 'left',
                  label: t('workspace.remaining'),
                  value: Math.max(0, approved - (spent ?? 0)),
                  colour: 'var(--ds-surface-active)',
                },
              ]}
            />
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle>{t('workspace.tab.work')}</CardTitle>
        </CardHeader>
        <CardContent className="grid grid-cols-3 gap-3 text-center">
          {[
            [t('workspace.tab.tasks'), openTasks],
            [t('workspace.overdue'), overdue],
            [t('workspace.tab.approvals'), waiting],
          ].map(([label, value]) => (
            <div key={String(label)} className="flex flex-col gap-1">
              <span className="ds-numeric text-content text-3xl font-semibold">
                {value === null ? '—' : formatNumber(Number(value), locale)}
              </span>
              <span className="text-content-tertiary text-xs">{label}</span>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}
