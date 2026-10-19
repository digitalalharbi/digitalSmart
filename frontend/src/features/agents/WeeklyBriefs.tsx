import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { compactMoney } from '@/shared/lib/compact-money';
import { formatNumber } from '@/shared/lib/format';
import { providerName } from '@/shared/lib/providers';
import { Button } from '@/shared/ui/button';
import type { AgentFinding } from './api';
import { useAgentFindings } from './api';
import { num, record, str } from './evidence';

const SHOWN = 6;

/**
 * The Reports agent's weekly briefs, one card per client, for a person to review before anything is sent.
 *
 * Every figure is the platforms' own, kept per currency: spend and results always, revenue and return only where a
 * platform reported a return. Each brief says how many days it covers and which platforms it drew on, so a reader
 * can tell a quiet week from a week nobody reported.
 */
export function WeeklyBriefs({ clientId }: { clientId?: string | null | undefined }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const [all, setAll] = useState(false);
  const findings = useAgentFindings(can(PERMISSIONS.analyticsView));

  const briefs = (findings.data ?? []).filter(
    (f) => f.reason.code === 'REPORT_READY' && (clientId == null || f.client?.id === clientId),
  );

  if (briefs.length === 0) {
    return null;
  }

  return (
    <section id="briefs" aria-labelledby="briefs-title" className="flex flex-col gap-3">
      <div className="flex items-baseline justify-between gap-3">
        <h2 id="briefs-title" className="text-content text-lg font-semibold">
          {t('agentFindings.briefs')}
        </h2>
        <span className="text-content-tertiary text-xs">
          {t('actionCenter.preparedBy', { agent: t('actionCenter.agents.reports') })}
        </span>
      </div>
      <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {(all ? briefs : briefs.slice(0, SHOWN)).map((brief) => (
          <li key={brief.id}>
            <Brief brief={brief} />
          </li>
        ))}
      </ul>
      {briefs.length > SHOWN ? (
        <Button variant="ghost" className="self-start" onClick={() => setAll((value) => !value)}>
          {all ? t('agentFindings.fewer') : t('agentFindings.allBriefs', { count: briefs.length })}
        </Button>
      ) : null}
    </section>
  );
}

function Brief({ brief }: { brief: AgentFinding }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const e = brief.evidence;
  const totals = Object.entries(record(e['totals']) ?? {});
  const sources = Array.isArray(e['sources'])
    ? (e['sources'] as unknown[]).filter((s) => typeof s === 'string')
    : [];
  const days = num(e['reported_days']);
  const best = str(e['best']);
  const worst = str(e['worst']);

  const initials = brief.subject.label
    .replace(/^DEMO\s+—\s+/u, '')
    .split(/\s+/u)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

  return (
    <article
      aria-label={brief.subject.label}
      className="bg-surface flex h-full flex-col gap-4 rounded-3xl p-5 shadow-xs"
    >
      {/* The cover: who it is for, how much it covers, which platforms it drew on — as marks, not a sentence. */}
      <header className="flex items-center gap-3">
        <span
          aria-hidden="true"
          className="bg-accent-subtle text-content-brand grid size-10 shrink-0 place-items-center rounded-2xl text-sm font-semibold"
        >
          {initials}
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="text-content truncate text-base font-semibold" dir="auto">
            {brief.subject.label}
          </h3>
          <ul
            className="mt-1 flex flex-wrap items-center gap-1"
            aria-label={t('agentFindings.coverage', {
              days: days === null ? '—' : formatNumber(days, locale),
              sources: sources.map((s) => providerName(s)).join(' · '),
            })}
          >
            <li className="bg-surface-sunken text-content-secondary ds-numeric rounded-full px-2 py-0.5 text-xs font-semibold">
              {days === null ? '—' : formatNumber(days, locale)}
              {t('agentFindings.daysShort')}
            </li>
            {sources.map((s) => (
              <li
                key={s}
                className="bg-surface-sunken text-content-tertiary rounded-full px-2 py-0.5 text-xs"
              >
                {providerName(s)}
              </li>
            ))}
          </ul>
        </div>
      </header>

      {totals.map(([currency, raw]) => {
        const row = record(raw) ?? {};
        const spend = num(row['spend_minor']);
        const results = num(row['results']);
        const revenue = num(row['revenue_minor']);
        const roas = num(row['roas']);

        return (
          <dl key={currency} className="grid grid-cols-3 gap-2">
            <Figure
              label={t('agentFindings.spend')}
              value={spend === null ? '—' : compactMoney(spend, currency, locale)}
            />
            <Figure
              label={t('agentFindings.results')}
              value={results === null ? '—' : formatNumber(Math.round(results), locale)}
            />
            <Figure
              label={roas === null ? t('agentFindings.revenue') : t('agentFindings.roas')}
              value={
                roas === null
                  ? t('agentFindings.notReported')
                  : `${formatNumber(roas, locale)}× · ${revenue === null ? '' : compactMoney(revenue, currency, locale)}`
              }
            />
          </dl>
        );
      })}

      {best === null && worst === null ? null : (
        <ul className="flex flex-col gap-1.5">
          {best === null ? null : (
            <li className="bg-success-surface text-success flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-medium">
              <TrendingUp aria-hidden className="size-4 shrink-0" />
              <span className="sr-only">{t('agentFindings.bestLabel')}</span>
              <span className="truncate" dir="auto">
                {best}
              </span>
            </li>
          )}
          {worst === null ? null : (
            <li className="bg-danger-surface text-danger flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-medium">
              <TrendingDown aria-hidden className="size-4 shrink-0" />
              <span className="sr-only">{t('agentFindings.worstLabel')}</span>
              <span className="truncate" dir="auto">
                {worst}
              </span>
            </li>
          )}
        </ul>
      )}
    </article>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-surface-sunken flex min-w-0 flex-col rounded-xl p-2">
      <dt className="text-content-tertiary truncate text-xs">{label}</dt>
      <dd className="ds-numeric text-content truncate text-sm font-semibold">{value}</dd>
    </div>
  );
}
