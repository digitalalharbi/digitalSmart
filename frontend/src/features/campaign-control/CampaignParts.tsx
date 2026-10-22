import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { InfoHint } from '@/shared/ui/info-hint';
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
export function BudgetBar({ row }: { row: Pick<CampaignRow, 'budget_utilisation'> }) {
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
          {[row.client_name, row.project_name]
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
      <HealthPill state={HEALTH_TO_STATE[row.health]} />
      {/* The platform's word, beside ours — rule 11. They disagree exactly when it matters. */}
      <Badge tone="neutral">{statusName(t, row)}</Badge>
      {withReason ? (
        /* Why it is judged so: one sentence, a hover away and always in the page — not a line under every card. */
        <InfoHint
          text={reasonText(
            t,
            row.health_reason_code === undefined
              ? null
              : { code: row.health_reason_code, params: row.health_reason_params ?? {} },
            row.health_reason,
          )}
        />
      ) : null}
    </span>
  );
}

export function CampaignCard({
  row,
  selected,
  onToggle,
}: {
  row: CampaignRow;
  selected?: boolean;
  onToggle?: () => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  return (
    <article
      aria-label={row.name}
      className="bg-surface border-line-subtle flex flex-col gap-3 rounded-2xl border p-4 shadow-xs"
    >
      <div className="flex items-start justify-between gap-2">
        {onToggle === undefined ? null : (
          <input
            type="checkbox"
            checked={selected === true}
            onChange={onToggle}
            aria-label={t('bulk.select', { name: row.name })}
            className="accent-action mt-1 size-4 shrink-0"
          />
        )}
        <CampaignIdentity row={row} />
        <PinButton row={row} />
      </div>
      <StatusPair row={row} withReason />

      <dl className="grid grid-cols-2 gap-2">
        <Tile
          label={t('campaignControl.col.spend')}
          value={money(row.spend_minor, row.currency, locale)}
          big
        />
        <Tile label={resultName(t, row)} value={resultText(row, locale)} big />
        <Tile
          label={t('campaignControl.col.costPerResult')}
          value={money(row.cost_per_result_minor, row.currency, locale)}
        />
        {/* Only where the objective measures revenue. The server withholds it for the rest. */}
        {row.roas === null ? null : <Tile label="ROAS" value={`${row.roas.toFixed(2)}×`} />}
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

function Tile({ label, value, big = false }: { label: string; value: string; big?: boolean }) {
  return (
    <div className="bg-surface-sunken min-w-0 rounded-xl p-2.5">
      <dt className="text-content-tertiary truncate text-xs capitalize">{label}</dt>
      <dd
        className={cn(
          'ds-numeric text-content truncate font-semibold',
          big ? 'text-lg' : 'text-sm',
        )}
      >
        {value}
      </dd>
    </div>
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
