import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { formatPercent } from '@/shared/lib/format';
import { money, resultText } from './text';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Badge } from '@/shared/ui/badge';
import { HealthPill } from '@/shared/ui/health';
import { Sparkline } from '@/shared/ui/sparkline';
import { ProviderLogo } from '@/features/integrations';
import { Pin, PinOff } from 'lucide-react';
import { usePinMutation, useShortcutsQuery } from '@/features/search';
import { HEALTH_TO_STATE, type CampaignRow } from './types';

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
      <div className="flex items-start justify-between gap-2">
        <CampaignIdentity row={row} />
        <PinButton row={row} />
      </div>
      <StatusPair row={row} />
      {row.tags.length === 0 ? null : (
        <ul className="flex flex-wrap gap-1" aria-label={t('campaignControl.tags')}>
          {row.tags.map((tag) => (
            <li key={tag.id}>
              <Badge tone="neutral">{tag.name}</Badge>
            </li>
          ))}
        </ul>
      )}
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

/** Keep a campaign close. Pinned state comes from the same shortcuts the palette reads. */
function PinButton({ row }: { row: CampaignRow }) {
  const { t } = useTranslation();
  const shortcuts = useShortcutsQuery();
  const pin = usePinMutation();
  const pinned = (shortcuts.data?.pinned ?? []).some(
    (hit) => hit.type === 'campaign' && hit.id === row.id,
  );

  return (
    <button
      type="button"
      aria-pressed={pinned}
      aria-label={t(pinned ? 'palette.unpin' : 'palette.pin', { name: row.name })}
      onClick={() => pin.mutate({ hit: { type: 'campaign', id: row.id }, pinned: !pinned })}
      className="text-content-tertiary hover:text-content shrink-0 rounded p-1"
    >
      {pinned ? <PinOff aria-hidden className="size-4" /> : <Pin aria-hidden className="size-4" />}
    </button>
  );
}
