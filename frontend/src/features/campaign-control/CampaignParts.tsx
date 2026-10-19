import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';
import { formatPercent } from '@/shared/lib/format';
import { money, resultText } from './text';
import { Badge } from '@/shared/ui/badge';
import { HealthPill } from '@/shared/ui/health';
import { Sparkline } from '@/shared/ui/sparkline';
import { ProviderLogo } from '@/features/integrations';
import { Pin, PinOff } from 'lucide-react';
import { usePinMutation, useShortcutsQuery } from '@/features/search';
import { resultName, statusName } from './labels';
import { Hierarchy } from './Hierarchy';
import { QuickActions } from './QuickActions';
import { reasonText } from '@/shared/lib/reason';
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
        <Link
          to={`/campaigns/${row.id}`}
          className="text-content truncate text-sm font-medium hover:underline"
        >
          {row.name}
        </Link>
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
  const { t } = useTranslation();

  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <HealthPill
        state={HEALTH_TO_STATE[row.health]}
        detail={
          withReason
            ? reasonText(
                t,
                row.health_reason_code === undefined
                  ? null
                  : { code: row.health_reason_code, params: row.health_reason_params ?? {} },
                row.health_reason,
              )
            : undefined
        }
      />
      {/* The platform's word, beside ours — rule 11. They disagree exactly when it matters. */}
      <Badge tone="neutral">{statusName(t, row)}</Badge>
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
      <StatusPair row={row} withReason />

      <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
        <div>
          <dt className="text-content-tertiary text-xs">{t('campaignControl.col.spend')}</dt>
          <dd className="ds-numeric text-content">
            {money(row.spend_minor, row.currency, locale)}
          </dd>
        </div>
        <div>
          <dt className="text-content-tertiary text-xs capitalize">{resultName(t, row)}</dt>
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

      <Hierarchy campaignId={row.id} />
      <QuickActions row={row} />
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
