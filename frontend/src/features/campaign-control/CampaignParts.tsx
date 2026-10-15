import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { formatPercent } from '@/shared/lib/format';
import { money, resultText } from './text';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Badge } from '@/shared/ui/badge';
import { HealthPill } from '@/shared/ui/health';
import { Sparkline } from '@/shared/ui/sparkline';
import { ProviderLogo } from '@/features/integrations';
import { HEALTH_TO_STATE, type CampaignRow, type HealthValue } from './types';

/** Average daily spend against a daily budget, as a bar plus the figure beside it. */
export function BudgetBar({ row }: { row: CampaignRow }) {
  const { t, i18n } = useTranslation();
  const used = row.budget_utilisation;

  if (used === null) {
    return (
      <span className="text-content-tertiary text-xs">{t('campaignControl.noDailyBudget')}</span>
    );
  }

  return (
    <span className="flex w-full flex-col gap-1">
      <span className="bg-surface-sunken block h-1.5 w-full overflow-hidden rounded-full">
        <span
          className={cn('block h-full rounded-full', used > 1.1 ? 'bg-warning' : 'bg-accent')}
          style={{ width: `${String(Math.min(100, Math.max(2, used * 100)))}%` }}
        />
      </span>
      <span className="text-content-tertiary text-xs">
        {t('campaignControl.ofDailyBudget', { value: formatPercent(used, i18n.language, 0) })}
      </span>
    </span>
  );
}

export function CampaignIdentity({ row }: { row: CampaignRow }) {
  return (
    <span className="flex min-w-0 items-center gap-3">
      <ProviderLogo provider={row.provider as never} className="size-8" />
      <span className="flex min-w-0 flex-col">
        <span className="text-content truncate text-sm font-medium">{row.name}</span>
        <span className="text-content-tertiary truncate text-xs">
          {[row.client_name, row.project_name, row.external_account.name]
            .filter((part): part is string => part !== null)
            .join(' · ')}
        </span>
      </span>
    </span>
  );
}

/** `withReason` only where no sentence sits beside it — otherwise a screen reader hears it twice. */
export function StatusPair({
  row,
  withReason = false,
}: {
  row: CampaignRow;
  withReason?: boolean;
}) {
  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <HealthPill
        state={HEALTH_TO_STATE[row.health]}
        detail={withReason ? row.health_reason : undefined}
      />
      {/* The platform's word, beside ours — rule 11. They disagree exactly when it matters. */}
      <Badge tone="neutral">{row.status_label}</Badge>
    </span>
  );
}

export function CampaignCard({ row }: { row: CampaignRow }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  return (
    <article
      aria-label={row.name}
      className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
    >
      <CampaignIdentity row={row} />
      <StatusPair row={row} />
      <p className="text-content-secondary text-sm">{row.health_reason}</p>

      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <div>
          <dt className="text-content-tertiary text-xs">{t('campaignControl.col.spend')}</dt>
          <dd className="ds-numeric text-content">
            {money(row.spend_minor, row.currency, locale)}
          </dd>
        </div>
        <div>
          <dt className="text-content-tertiary text-xs capitalize">
            {row.result_label.replace('_', ' ')}
          </dt>
          <dd className="ds-numeric text-content">{resultText(row, locale)}</dd>
        </div>
        <div>
          <dt className="text-content-tertiary text-xs">
            {t('campaignControl.col.costPerResult')}
          </dt>
          <dd className="ds-numeric text-content">
            {money(row.cost_per_result_minor, row.currency, locale)}
          </dd>
        </div>
        {/* Only where the objective measures revenue. The server withholds it for the rest. */}
        {row.roas === null ? null : (
          <div>
            <dt className="text-content-tertiary text-xs">ROAS</dt>
            <dd className="ds-numeric text-content">{row.roas.toFixed(2)}×</dd>
          </div>
        )}
      </dl>

      <div className="flex items-end justify-between gap-3">
        <div className="min-w-0 flex-1">
          <BudgetBar row={row} />
        </div>
        <Sparkline values={row.trend} label={t('campaignControl.trendLabel', { name: row.name })} />
      </div>

      <p className="text-content-tertiary text-xs">
        {row.last_synced_at === null
          ? t('campaignControl.neverUpdated')
          : t('campaignControl.updated', { when: formatRelativeTime(row.last_synced_at, locale) })}
      </p>
    </article>
  );
}

/** Six states as one stacked bar, each segment a button that narrows the list to it. */
export function HealthDistribution({
  counts,
  selected,
  onSelect,
  order,
  labels,
}: {
  counts: Record<HealthValue, number>;
  selected: HealthValue | null;
  onSelect: (health: HealthValue | null) => void;
  order: HealthValue[];
  labels: Record<HealthValue, string>;
}) {
  const total = order.reduce((sum, key) => sum + counts[key], 0);
  const tone: Record<HealthValue, string> = {
    healthy: 'bg-success',
    watch: 'bg-warning',
    needs_attention: 'bg-warning',
    critical: 'bg-danger',
    inactive: 'bg-content-tertiary',
    insufficient_data: 'bg-line',
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="bg-surface-sunken flex h-3 w-full overflow-hidden rounded-full" aria-hidden>
        {order.map((key) =>
          counts[key] === 0 || total === 0 ? null : (
            <span
              key={key}
              className={cn(tone[key], 'h-full')}
              style={{ width: `${String((counts[key] / total) * 100)}%` }}
            />
          ),
        )}
      </div>
      <ul className="flex flex-wrap gap-2">
        {order.map((key) => (
          <li key={key}>
            <button
              type="button"
              aria-pressed={selected === key}
              onClick={() => onSelect(selected === key ? null : key)}
              className={cn(
                'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium',
                selected === key
                  ? 'border-accent bg-accent-subtle text-content-brand'
                  : 'border-line text-content-secondary hover:bg-surface-hover',
              )}
            >
              <span aria-hidden className={cn('size-2 rounded-full', tone[key])} />
              {labels[key]}
              <span className="ds-numeric">{counts[key]}</span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
