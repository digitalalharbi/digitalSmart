import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatMinor } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { ScrollRegion } from '@/shared/ui/scroll-region';
import { Skeleton } from '@/shared/ui/skeleton';
import { periodOfDays, formatDuration } from '../funnels/duration';
import { ProjectScope } from '../health/ProjectScope';
import { useLinksQuery } from '../links/api';
import { useOperationsQuery } from './api';
import type { AttentionItem, Money, OpsFilters, OpsLink, Operations, TeamRow } from './types';

const SELECT =
  'border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';
const NONE: OpsFilters = {
  platform: '',
  campaign_id: '',
  ad_group_id: '',
  ad_id: '',
  link_id: '',
  funnel_id: '',
};
const STAGE_ORDER = [
  'link_click',
  'landing_view',
  'lead_created',
  'lead_contacted',
  'lead_qualified',
  'appointment_booked',
  'sale_completed',
];

/** A number, or "Not reported" with the reason on hover and for assistive technology. Never a zero standing in for either. */
function Figure({
  label,
  value,
  reason,
  hint,
}: {
  label: string;
  value: string | null;
  reason?: string | null;
  hint?: string;
}) {
  const { t } = useTranslation();

  return (
    <div className="bg-surface-sunken flex min-w-0 flex-col gap-0.5 rounded-2xl p-3">
      <dt className="text-content-tertiary text-xs">{label}</dt>
      <dd className="text-content text-lg font-semibold tabular-nums">
        {value ?? <span title={reason ?? undefined}>{t('tracking.links.notReported')}</span>}
      </dd>
      {value === null && reason ? (
        <span className="text-content-tertiary text-xs">{reason}</span>
      ) : null}
      {hint ? <span className="text-content-tertiary text-xs">{hint}</span> : null}
    </div>
  );
}

function Kpis({ data }: { data: Operations }) {
  const { t, i18n } = useTranslation();
  const k = data.kpis;
  const money = (minor: number | null, currency: string): string | null =>
    minor === null ? null : formatMinor(minor, currency, i18n.language);
  const pct = (v: number | null): string | null => (v === null ? null : `${Math.round(v * 100)}%`);
  const secs = (v: number | null): string | null =>
    v === null ? null : formatDuration(v, i18n.language);
  const noSpend = t('tracking.ops.noPricedLinks');

  return (
    <div className="flex flex-col gap-4">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <Figure
          label={t('tracking.ops.kpi.leads')}
          value={String(k.tracked.leads)}
          hint={t('tracking.ops.kpi.leadsHint', { count: k.tracked.clicks })}
        />
        <Figure label={t('tracking.ops.kpi.qualified')} value={String(k.tracked.qualified)} />
        <Figure label={t('tracking.ops.kpi.appointments')} value={String(k.tracked.appointments)} />
        <Figure label={t('tracking.ops.kpi.sales')} value={String(k.tracked.sales)} />
        <Figure
          label={t('tracking.ops.kpi.medianAttempt')}
          value={secs(k.response.median_attempt_seconds)}
          reason={t('tracking.ops.noAttempts')}
        />
        <Figure
          label={t('tracking.ops.kpi.sla')}
          value={pct(k.response.sla_compliance)}
          reason={
            k.response.sla_minutes === null
              ? t('tracking.ops.noPromise')
              : t('tracking.ops.noLeadsYet')
          }
        />
      </dl>
      {k.by_currency.length === 0 ? (
        <Alert tone="info">{noSpend}</Alert>
      ) : (
        k.by_currency.map((m: Money) => (
          <div key={m.currency} className="flex flex-col gap-2">
            <p className="text-content-secondary text-xs">
              {t('tracking.ops.pricedNote', {
                currency: m.currency,
                count: m.priced_links,
                unpriced: k.unpriced_links,
              })}
            </p>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              <Figure
                label={t('tracking.ops.kpi.spend')}
                value={money(m.spend_minor, m.currency)}
              />
              <Figure
                label={t('tracking.ops.kpi.cpl')}
                value={money(m.cpl_minor, m.currency)}
                reason={t('tracking.ops.noLeadsYet')}
              />
              <Figure
                label={t('tracking.ops.kpi.cpql')}
                value={money(m.cpql_minor, m.currency)}
                reason={t('tracking.ops.noQualifiedYet')}
              />
              <Figure
                label={t('tracking.ops.kpi.cac')}
                value={money(m.cac_minor, m.currency)}
                reason={t('tracking.ops.noSalesYet')}
              />
              <Figure
                label={t('tracking.ops.kpi.wonValue')}
                value={money(m.revenue.lead_value_minor, m.currency)}
                reason={t('tracking.ops.noRevenue')}
              />
              <Figure
                label={t('tracking.ops.kpi.roasWon')}
                value={m.roas.lead_value === null ? null : `${m.roas.lead_value.toFixed(2)}×`}
                reason={t('tracking.ops.noRevenue')}
              />
              <Figure
                label={t('tracking.ops.kpi.storeValue')}
                value={money(m.revenue.store_attributed_minor, m.currency)}
                reason={t('tracking.ops.noStoreOrders')}
              />
              <Figure
                label={t('tracking.ops.kpi.roasStore')}
                value={
                  m.roas.store_attributed === null ? null : `${m.roas.store_attributed.toFixed(2)}×`
                }
                reason={t('tracking.ops.noStoreOrders')}
              />
            </dl>
          </div>
        ))
      )}
    </div>
  );
}

function Hero({ data }: { data: Operations }) {
  const { t, i18n } = useTranslation();
  const stages = [...data.funnel.stages].sort(
    (a, b) => STAGE_ORDER.indexOf(a.source) - STAGE_ORDER.indexOf(b.source),
  );

  return (
    <Card className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <h2 className="text-content text-base font-semibold">{t('tracking.ops.funnel')}</h2>
      <ol className="flex flex-col gap-2" aria-label={t('tracking.ops.funnel')}>
        {stages.map((s) => (
          <li
            key={s.source}
            className="bg-surface-sunken flex min-w-0 flex-wrap items-center justify-between gap-2 rounded-2xl p-3"
          >
            <span className="text-content text-sm font-medium">
              {t(`tracking.funnels.sources.${s.source}`, { defaultValue: s.source })}
            </span>
            <span className="text-content flex items-center gap-3 text-sm tabular-nums">
              <strong>{s.count === null ? t('tracking.links.notReported') : s.count}</strong>
              {s.conversion_from_previous !== null ? (
                <span className="text-content-secondary">
                  {Math.round(s.conversion_from_previous * 100)}%
                </span>
              ) : null}
              {s.drop_off !== null ? (
                <span className="text-content-tertiary">−{s.drop_off}</span>
              ) : null}
              {s.median_seconds_from_previous !== null ? (
                <span className="text-content-tertiary">
                  {formatDuration(s.median_seconds_from_previous, i18n.language)}
                </span>
              ) : null}
            </span>
            {s.count === null && s.reason ? (
              <span className="text-content-tertiary basis-full text-xs">{s.reason}</span>
            ) : null}
          </li>
        ))}
      </ol>
      {data.funnel.scope === 'chosen_links' ? (
        <p className="text-content-tertiary text-xs">{t('tracking.ops.chosenFunnel')}</p>
      ) : null}
    </Card>
  );
}

function LinksTable({ links }: { links: OpsLink[] }) {
  const { t, i18n } = useTranslation();
  const cost = (c: {
    minor: number | null;
    currency: string | null;
    reason: string | null;
  }): string =>
    c.minor === null || c.currency === null
      ? t('tracking.links.notReported')
      : formatMinor(c.minor, c.currency, i18n.language);
  const n = (v: number | null): string =>
    v === null ? t('tracking.links.notReported') : String(v);

  return (
    <Card className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <h2 className="text-content text-base font-semibold">{t('tracking.ops.linksTable')}</h2>
      <ScrollRegion label={t('tracking.ops.linksTable')}>
        <table className="w-full min-w-max text-sm">
          <thead>
            <tr className="text-content-tertiary text-xs">
              {[
                'link',
                'platform',
                'campaign',
                'spend',
                'clicks',
                'leads',
                'qualified',
                'appointments',
                'sales',
                'cpl',
                'cpql',
                'cac',
                'sla',
                'tracking',
              ].map((c) => (
                <th key={c} scope="col" className="px-2 py-2 text-start font-medium">
                  {t(`tracking.ops.cols.${c}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {links.map((l) => (
              <tr key={l.link.id} className="border-line-subtle border-t align-top">
                <th scope="row" className="px-2 py-2 text-start font-medium">
                  {l.link.name}
                </th>
                <td className="px-2 py-2">{l.platform ?? '—'}</td>
                <td className="px-2 py-2">
                  {[l.campaign, l.ad_group, l.ad].filter(Boolean).join(' › ') || '—'}
                </td>
                <td className="px-2 py-2 tabular-nums" title={l.spend.reason ?? undefined}>
                  {l.spend.minor !== null && l.spend.currency !== null
                    ? formatMinor(l.spend.minor, l.spend.currency, i18n.language)
                    : t('tracking.links.notReported')}
                  <Badge
                    tone={
                      l.spend.basis === 'DIRECT'
                        ? 'success'
                        : l.spend.basis === 'ALLOCATED'
                          ? 'warning'
                          : 'neutral'
                    }
                  >
                    {t(`tracking.performance.basis.${l.spend.basis}`)}
                  </Badge>
                </td>
                <td className="px-2 py-2 tabular-nums">{l.clicks}</td>
                <td className="px-2 py-2 tabular-nums">{l.leads}</td>
                <td className="px-2 py-2 tabular-nums">{n(l.qualified)}</td>
                <td className="px-2 py-2 tabular-nums">{n(l.appointments)}</td>
                <td className="px-2 py-2 tabular-nums">{n(l.sales)}</td>
                <td className="px-2 py-2 tabular-nums" title={l.cpl.reason ?? undefined}>
                  {cost(l.cpl)}
                </td>
                <td className="px-2 py-2 tabular-nums" title={l.cpql.reason ?? undefined}>
                  {cost(l.cpql)}
                </td>
                <td className="px-2 py-2 tabular-nums" title={l.cac.reason ?? undefined}>
                  {cost(l.cac)}
                </td>
                <td className="px-2 py-2 tabular-nums">
                  {l.sla === null || l.sla.met === null
                    ? t('tracking.links.notReported')
                    : `${Math.round((l.sla.met / l.sla.leads) * 100)}%`}
                </td>
                <td className="px-2 py-2">{t(`tracking.ops.trackingState.${l.tracking}`)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </ScrollRegion>
    </Card>
  );
}

function Team({ rows }: { rows: TeamRow[] }) {
  const { t, i18n } = useTranslation();
  const secs = (v: number | null): string =>
    v === null ? t('tracking.links.notReported') : formatDuration(v, i18n.language);

  if (rows.length === 0) {
    return null;
  }

  return (
    <Card className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <h2 className="text-content text-base font-semibold">{t('tracking.ops.team')}</h2>
      <p className="text-content-tertiary text-xs">{t('tracking.ops.teamNote')}</p>
      <ScrollRegion label={t('tracking.ops.team')}>
        <table className="w-full min-w-max text-sm">
          <thead>
            <tr className="text-content-tertiary text-xs">
              {[
                'person',
                'assigned',
                'contacted',
                'qualified',
                'medianAttempt',
                'medianContact',
                'slaShare',
                'overdue',
              ].map((c) => (
                <th key={c} scope="col" className="px-2 py-2 text-start font-medium">
                  {t(`tracking.ops.teamCols.${c}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={r.owner?.id ?? `none-${String(i)}`} className="border-line-subtle border-t">
                <th scope="row" className="px-2 py-2 text-start font-medium">
                  {r.owner?.name ?? t('tracking.followUp.unassigned')}
                </th>
                <td className="px-2 py-2 tabular-nums">{r.assigned}</td>
                <td className="px-2 py-2 tabular-nums">{r.contacted}</td>
                <td className="px-2 py-2 tabular-nums">{r.qualified}</td>
                <td className="px-2 py-2 tabular-nums">{secs(r.median_attempt_seconds)}</td>
                <td className="px-2 py-2 tabular-nums">{secs(r.median_contact_seconds)}</td>
                <td className="px-2 py-2 tabular-nums">
                  {r.sla_compliance === null
                    ? t('tracking.links.notReported')
                    : `${Math.round(r.sla_compliance * 100)}%`}
                </td>
                <td className="px-2 py-2 tabular-nums">
                  {r.overdue === null ? t('tracking.links.notReported') : r.overdue}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </ScrollRegion>
    </Card>
  );
}

function Attribution({ a }: { a: Operations['attribution'] }) {
  const { t } = useTranslation();
  const rows: [string, string][] = [
    [
      t('tracking.ops.attr.platform'),
      a.platform_reported.results === null
        ? t('tracking.links.notReported')
        : String(a.platform_reported.results),
    ],
    [t('tracking.ops.attr.first'), String(a.first_known_touch.leads_with_a_known_first_touch)],
    [t('tracking.ops.attr.last'), String(a.last_known_touch.leads)],
    [t('tracking.ops.attr.ours'), String(a.digitalsmart_attributed.leads)],
    [t('tracking.ops.attr.outcome'), String(a.outcome.sales)],
  ];

  return (
    <Card className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <h2 className="text-content text-base font-semibold">{t('tracking.ops.attribution')}</h2>
      <p className="text-content-secondary text-xs">{t('tracking.ops.attrNote')}</p>
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        {rows.map(([label, value]) => (
          <Figure key={label} label={label} value={value} />
        ))}
      </dl>
      <p className="text-content-tertiary text-xs">
        {a.coverage === null
          ? t('tracking.ops.attrNoLeads')
          : t('tracking.ops.attrCoverage', {
              coverage: Math.round(a.coverage * 100),
              confidence: t(`tracking.ops.confidence.${a.confidence}`),
              missing: a.missing_evidence,
            })}
      </p>
    </Card>
  );
}

function Attention({ items }: { items: AttentionItem[] }) {
  const { t } = useTranslation();

  return (
    <Card className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <h2 className="text-content text-base font-semibold">{t('tracking.ops.attention')}</h2>
      {items.length === 0 ? (
        <p className="text-content-secondary text-sm">{t('tracking.ops.attentionNone')}</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {items.map((i, index) => (
            <li
              key={`${i.code}-${String(index)}`}
              className="bg-surface-sunken flex min-w-0 flex-col gap-1 rounded-2xl p-3"
            >
              <p className="text-content text-sm font-medium">
                {t(`tracking.ops.attn.${i.code}`, { subject: i.subject ?? '' })}
              </p>
              <p className="text-content-tertiary text-xs" dir="ltr">
                {Object.entries(i.evidence)
                  .filter(([k]) => k !== 'link_id')
                  .map(([k, v]) => `${k}: ${v === null ? '—' : String(v)}`)
                  .join(' · ')}
              </p>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/** Are marketing and follow-up working together? Every figure shows what it rests on, and a figure nothing supports is "not reported". */
export function OperationsTab() {
  const { t } = useTranslation();
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [days, setDays] = useState(30);
  const [filters, setFilters] = useState<OpsFilters>(NONE);
  const period = useMemo(() => periodOfDays(days), [days]);
  const links = useLinksQuery(project?.id ?? null);
  const ops = useOperationsQuery(project?.id ?? null, period.from, period.to, filters);
  const set = (key: keyof OpsFilters) => (value: string) =>
    setFilters((f) => ({ ...f, [key]: value }));

  const options = useMemo(() => {
    const platforms = new Set<string>();
    const campaigns = new Map<string, string>();
    const groups = new Map<string, string>();
    const ads = new Map<string, string>();

    for (const l of links.data ?? []) {
      if (l.platform) platforms.add(l.platform);
      if (l.binding.campaign_id && l.campaign) campaigns.set(l.binding.campaign_id, l.campaign);
      if (l.binding.ad_group_id && l.ad_group) groups.set(l.binding.ad_group_id, l.ad_group);
      if (l.binding.ad_id && l.ad) ads.set(l.binding.ad_id, l.ad);
    }

    return {
      platforms: [...platforms],
      campaigns: [...campaigns],
      groups: [...groups],
      ads: [...ads],
    };
  }, [links.data]);

  const pick = (label: string, key: keyof OpsFilters, entries: [string, string][]) => (
    <Field label={label}>
      {(f) => (
        <div className="-mx-1 min-w-0 overflow-x-clip px-1">
          <select
            {...f}
            className={SELECT}
            value={filters[key]}
            onChange={(e) => set(key)(e.target.value)}
          >
            <option value="">{t('tracking.ops.all')}</option>
            {entries.map(([id, name]) => (
              <option key={id} value={id}>
                {name}
              </option>
            ))}
          </select>
        </div>
      )}
    </Field>
  );

  return (
    <div className="flex min-w-0 flex-col gap-5">
      <p className="text-content-secondary max-w-prose text-sm">{t('tracking.ops.intro')}</p>
      <ProjectScope
        value={project}
        onChange={(value) => {
          setProject(value);
          setFilters(NONE);
        }}
      />
      {project === null ? (
        <EmptyState
          title={t('tracking.funnels.pick.title')}
          description={t('tracking.funnels.pick.body')}
        />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Field label={t('tracking.ops.period')}>
              {(f) => (
                <div className="-mx-1 min-w-0 overflow-x-clip px-1">
                  <select
                    {...f}
                    className={SELECT}
                    value={days}
                    onChange={(e) => setDays(Number(e.target.value))}
                  >
                    {[7, 30, 90].map((d) => (
                      <option key={d} value={d}>
                        {t('tracking.ops.lastDays', { count: d })}
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </Field>
            {pick(
              t('tracking.ops.cols.platform'),
              'platform',
              options.platforms.map((p) => [
                p,
                t(`tracking.links.platforms.${p}`, { defaultValue: p }),
              ]),
            )}
            {pick(t('tracking.ops.filterCampaign'), 'campaign_id', options.campaigns)}
            {pick(t('tracking.ops.filterAdGroup'), 'ad_group_id', options.groups)}
            {pick(t('tracking.ops.filterAd'), 'ad_id', options.ads)}
            {pick(
              t('tracking.ops.cols.link'),
              'link_id',
              (links.data ?? []).map((l) => [l.id, l.name]),
            )}
          </div>

          {ops.isPending ? (
            <Skeleton className="h-48 w-full" />
          ) : ops.isError ? (
            <Alert tone="danger">{t('tracking.ops.failed')}</Alert>
          ) : (
            <>
              {ops.data.truncated ? (
                <Alert tone="warning">
                  {t('tracking.ops.truncated', { count: ops.data.links_considered })}
                </Alert>
              ) : null}
              <Kpis data={ops.data} />
              <Hero data={ops.data} />
              <LinksTable links={ops.data.links} />
              <Attention items={ops.data.attention} />
              <Team rows={ops.data.team} />
              <Attribution a={ops.data.attribution} />
            </>
          )}
        </>
      )}
    </div>
  );
}
