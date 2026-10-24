import { useMemo, useState } from 'react';
import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Copy } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { formatNumber, formatPercent } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { FilterSelect } from '@/shared/ui/filter-select';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { TabList, type TabItem } from '@/shared/ui/tab-list';
import { SlaBadge, StatusBadge } from './AccessBadges';
import { useAccessFiguresQuery, useAccessInboxQuery, useSaveSettingsMutation } from './api';
import type { AccessRequestRow, AccessStatus } from './types';

type Tab = 'new' | 'follow_up' | 'waiting' | 'approved' | 'rejected' | 'closed';

/** Which statuses each tab shows. "Needs follow-up" is what somebody has reached or judged and not yet decided. */
const TAB_STATUSES: Record<Tab, AccessStatus[]> = {
  new: ['new'],
  follow_up: ['contacted', 'qualified'],
  waiting: ['waiting_for_reply'],
  approved: ['approved'],
  rejected: ['rejected'],
  closed: ['closed'],
};

const TABS: Tab[] = ['new', 'follow_up', 'waiting', 'approved', 'rejected', 'closed'];

const RESPONSE_CHOICES = [15, 30, 60, 240, 1440];

/**
 * Requests for an account and enquiries, as the people who answer them see them.
 *
 * Not a CRM: one table, a handful of states, and the figures that say whether people are being answered. The figures keep
 * their denominators visible — a response rate over two requests is shown as over two, not as a percentage that looks like
 * a trend.
 */
export function AccessRequestsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can } = useSession();
  const inbox = useAccessInboxQuery();
  const figures = useAccessFiguresQuery(30);
  const save = useSaveSettingsMutation();
  const [tab, setTab] = useState<Tab>('new');

  const rows = useMemo(
    () => (inbox.data?.rows ?? []).filter((row) => TAB_STATUSES[tab].includes(row.status)),
    [inbox.data, tab],
  );

  if (!can(PERMISSIONS.usersInvite)) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader title={t('access.title')} />
        <Alert tone="warning">{t('access.forbidden')}</Alert>
      </div>
    );
  }

  const counts = inbox.data?.counts;
  const countFor = (entry: Tab): number =>
    TAB_STATUSES[entry].reduce((sum, status) => sum + (counts?.[status] ?? 0), 0);
  const tabs: Array<TabItem<Tab>> = TABS.map((entry) => ({
    id: entry,
    label: t(`access.tabs.${entry}`),
    badge:
      counts === undefined ? undefined : (
        <span className="ds-numeric">{formatNumber(countFor(entry), locale)}</span>
      ),
  }));

  const settings = inbox.data?.settings;
  const saveError = save.error instanceof ApiError ? save.error.message : null;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title={t('access.title')} description={t('access.subtitle')} />

      <FiguresStrip figures={figures.data} locale={locale} />

      {settings === undefined ? null : (
        <section
          aria-label={t('access.settings.title')}
          className="border-line bg-surface grid grid-cols-1 gap-3 rounded-2xl border p-4 sm:grid-cols-2"
        >
          <FilterSelect
            label={t('access.settings.response')}
            value={String(settings.response_minutes)}
            any={null}
            options={[...new Set([...RESPONSE_CHOICES, settings.response_minutes])]
              .sort((a, b) => a - b)
              .map((minutes) => ({
                value: String(minutes),
                label: t('access.settings.minutes', { count: minutes }),
              }))}
            onChange={(value) => {
              if (value !== null) {
                save.mutate({
                  response_minutes: Number(value),
                  default_owner_id: settings.default_owner_id,
                });
              }
            }}
          />
          <FilterSelect
            label={t('access.settings.owner')}
            value={settings.default_owner_id}
            any={t('access.settings.nobody')}
            options={(inbox.data?.owners ?? []).map((owner) => ({
              value: owner.id,
              label: owner.name,
            }))}
            onChange={(value) =>
              save.mutate({
                response_minutes: settings.response_minutes,
                default_owner_id: value,
              })
            }
          />
          {saveError === null ? null : (
            <div className="sm:col-span-2">
              <Alert tone="danger">{saveError}</Alert>
            </div>
          )}
        </section>
      )}

      <TabList
        tabs={tabs}
        active={tab}
        onChange={setTab}
        label={t('access.tabs.label')}
        idPrefix="access"
      />

      <div role="tabpanel" id="access-panel" aria-labelledby={`access-tab-${tab}`}>
        {inbox.isPending ? (
          <Skeleton className="h-40 w-full" />
        ) : inbox.isError ? (
          <Alert tone="danger">{t('access.failed')}</Alert>
        ) : rows.length === 0 ? (
          <EmptyState title={t('access.empty.title')} description={t('access.empty.body')} />
        ) : (
          <ul className="grid grid-cols-1 gap-3" aria-label={t(`access.tabs.${tab}`)}>
            {rows.map((row) => (
              <RequestCard key={row.id} row={row} locale={locale} />
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}

function RequestCard({ row, locale }: { row: AccessRequestRow; locale: string }) {
  const { t } = useTranslation();

  return (
    <li className="border-line bg-surface min-w-0 rounded-2xl border p-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <Link
            to={`/access-requests/${row.id}`}
            dir="auto"
            className="text-content text-base font-semibold hover:underline"
          >
            {row.name}
          </Link>
          {row.company === null ? null : (
            <p dir="auto" className="text-content-secondary truncate text-sm">
              {row.company}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge tone="neutral">{t(`access.kind.${row.kind}`)}</Badge>
          <StatusBadge status={row.status} />
          <SlaBadge sla={row.sla} />
          {row.possible_duplicate ? <Badge tone="warning">{t('access.duplicate')}</Badge> : null}
        </div>
      </div>
      <dl className="text-content-secondary mt-3 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <Fact
          label={t('access.fields.contact')}
          value={t(`access.contact.${row.preferred_contact}`)}
        />
        <Fact
          label={t('access.fields.created')}
          value={formatRelativeTime(row.created_at, locale)}
        />
        <Fact label={t('access.fields.owner')} value={row.owner_name ?? t('access.unassigned')} />
        <Fact
          label={t('access.fields.lastContact')}
          value={
            row.last_contact_at === null
              ? t('access.none')
              : formatRelativeTime(row.last_contact_at, locale)
          }
        />
        <Fact
          label={t('access.fields.nextFollowUp')}
          value={
            row.next_follow_up_at === null
              ? t('access.none')
              : formatRelativeTime(row.next_follow_up_at, locale)
          }
        />
      </dl>
    </li>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex min-w-0 gap-1.5">
      <dt className="text-content-tertiary shrink-0">{label}</dt>
      <dd dir="auto" className="min-w-0 truncate">
        {value}
      </dd>
    </div>
  );
}

/** Small operational figures. A rate over nothing is "no data yet", and one over a few shows how few. */
function FiguresStrip({
  figures,
  locale,
}: {
  figures: ReturnType<typeof useAccessFiguresQuery>['data'];
  locale: string;
}) {
  const { t } = useTranslation();

  if (figures === undefined) {
    return null;
  }

  const minutes =
    figures.median_first_response_seconds === null
      ? null
      : Math.max(1, Math.round(figures.median_first_response_seconds / 60));

  const items: Array<{ key: string; value: string; note?: string | undefined }> = [
    { key: 'new', value: formatNumber(figures.new_requests, locale) },
    { key: 'contacted', value: formatNumber(figures.contacted, locale) },
    { key: 'qualified', value: formatNumber(figures.qualified, locale) },
    { key: 'approved', value: formatNumber(figures.approved, locale) },
    { key: 'rejected', value: formatNumber(figures.rejected, locale) },
    {
      key: 'median',
      value:
        minutes === null
          ? t('access.figures.noData')
          : t('access.settings.minutes', { count: minutes }),
    },
    {
      key: 'sla',
      value:
        figures.sla_compliance === null
          ? t('access.figures.noData')
          : formatPercent(figures.sla_compliance, locale, 0),
      note:
        figures.sla_judged === 0
          ? undefined
          : t('access.figures.over', { count: figures.sla_judged }),
    },
    {
      key: 'acceptance',
      value:
        figures.invitation_acceptance === null
          ? t('access.figures.noData')
          : formatPercent(figures.invitation_acceptance, locale, 0),
      note:
        figures.invitations_made === 0
          ? undefined
          : t('access.figures.over', { count: figures.invitations_made }),
    },
  ];

  return (
    <section aria-label={t('access.figures.title', { count: figures.days })}>
      <h2 className="text-content-secondary mb-2 text-sm font-medium">
        {t('access.figures.title', { count: figures.days })}
      </h2>
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {items.map((item) => (
          <div key={item.key} className="border-line bg-surface min-w-0 rounded-2xl border p-3">
            <dt className="text-content-secondary truncate text-xs">
              {t(`access.figures.${item.key}`)}
            </dt>
            <dd className="ds-numeric text-content text-xl font-semibold">
              {item.value}
              {item.note === undefined ? null : (
                <span className="text-content-tertiary ms-1.5 text-xs font-normal">
                  {item.note}
                </span>
              )}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

/** Copy of a one-time link; the caller shows it only while it is the one time. */
export function CopyLink({ value, label, done }: { value: string; label: string; done: string }) {
  const [copied, setCopied] = useState(false);

  return (
    <Button
      type="button"
      variant="secondary"
      size="sm"
      onClick={() => {
        void navigator.clipboard
          .writeText(value)
          .then(() => setCopied(true))
          .catch(() => setCopied(false));
      }}
    >
      <Copy aria-hidden className="size-4" />
      {copied ? done : label}
    </Button>
  );
}
