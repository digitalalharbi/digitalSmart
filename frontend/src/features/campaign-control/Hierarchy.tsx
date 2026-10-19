import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, ChevronRight, Image as ImageIcon, PauseCircle, Video } from 'lucide-react';
import { api } from '@/shared/api/client';
import { compactMoney } from '@/shared/lib/compact-money';
import { cn } from '@/shared/lib/cn';
import { Skeleton } from '@/shared/ui/skeleton';

type Ad = {
  id: string;
  name: string;
  status: string;
  creative: {
    name: string;
    format: string;
    title: string | null;
    thumbnail_url: string | null;
  } | null;
  spend_minor: number | null;
  ctr_now: number | null;
  ctr_change: number | null;
  frequency: number | null;
};

type Group = {
  id: string;
  name: string;
  status: string;
  budget_mode: string;
  budget_minor: number | null;
  currency: string | null;
  audience: string | null;
  spend_minor: number | null;
  conversions: number | null;
  ads: Ad[];
};

type Hierarchy = { groups: Group[]; writes: { ad_group: string; ad: string } };

/** Click-through change past which an ad is shown as tiring. The Creative agent uses the same line. */
const TIRING = -0.25;

/**
 * Campaign → ad group → ad, opened in place.
 *
 * No separate page per level: a campaign card opens into its ad groups, and each ad group shows its ads with the
 * creative's own preview, its click-through trend and how often the same people saw it. Pausing an ad group or an ad
 * is shown, disabled, with the reason — no platform's write for those levels has been read in its documentation, so
 * a working-looking button would be a lie.
 */
export function Hierarchy({
  campaignId,
  defaultOpen = false,
}: {
  campaignId: string;
  defaultOpen?: boolean;
}) {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(defaultOpen);

  const query = useQuery({
    queryKey: ['campaign-hierarchy', campaignId] as const,
    queryFn: async (): Promise<Hierarchy> => {
      const { data } = await api.get<{ data: Hierarchy }>(`/campaigns/${campaignId}/hierarchy`);

      return data.data;
    },
    enabled: open,
    staleTime: 60_000,
  });

  const money = (minor: number | null, currency: string | null) =>
    minor === null || currency === null ? '—' : compactMoney(minor, currency, i18n.language);

  return (
    <div className="flex flex-col gap-2">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="text-content-secondary hover:text-content inline-flex w-fit items-center gap-1 text-sm font-medium"
      >
        {open ? (
          <ChevronDown aria-hidden className="size-4" />
        ) : (
          <ChevronRight aria-hidden className="size-4 rtl:-scale-x-100" />
        )}
        {t('hierarchy.toggle')}
      </button>

      {!open ? null : query.isPending ? (
        <Skeleton className="h-24 rounded-2xl" />
      ) : query.data === undefined || query.data.groups.length === 0 ? (
        <p className="text-content-tertiary text-sm">{t('hierarchy.none')}</p>
      ) : (
        <ul className="flex flex-col gap-2" aria-label={t('hierarchy.groups')}>
          {query.data.groups.map((group) => (
            <li key={group.id} className="bg-surface-sunken flex flex-col gap-2 rounded-2xl p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex min-w-0 flex-col">
                  <span className="text-content truncate text-sm font-semibold">{group.name}</span>
                  {group.audience === null ? null : (
                    <span className="text-content-tertiary truncate text-xs">{group.audience}</span>
                  )}
                </span>
                <span className="flex items-center gap-2 text-xs">
                  <span className="ds-numeric text-content font-semibold">
                    {money(group.spend_minor, group.currency)}
                  </span>
                  {group.budget_minor === null ? null : (
                    <span className="text-content-tertiary">
                      {t('hierarchy.daily', { amount: money(group.budget_minor, group.currency) })}
                    </span>
                  )}
                  <button
                    type="button"
                    disabled
                    title={t('hierarchy.notDocumented')}
                    aria-label={`${t('hierarchy.pause')} — ${t('hierarchy.notDocumented')}`}
                    className="text-content-tertiary cursor-not-allowed rounded-full p-1 opacity-60"
                  >
                    <PauseCircle aria-hidden className="size-4" />
                  </button>
                </span>
              </div>

              <ul className="flex flex-col gap-1.5">
                {group.ads.map((ad) => {
                  const tiring = ad.ctr_change !== null && ad.ctr_change <= TIRING;

                  return (
                    <li key={ad.id} className="bg-surface flex items-center gap-3 rounded-xl p-2">
                      <span className="bg-surface-active grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg">
                        {ad.creative?.thumbnail_url ? (
                          <img
                            src={ad.creative.thumbnail_url}
                            alt={ad.creative.name}
                            className="size-full object-cover"
                            loading="lazy"
                          />
                        ) : ad.creative?.format === 'video' ? (
                          <Video aria-hidden className="text-content-tertiary size-5" />
                        ) : (
                          <ImageIcon aria-hidden className="text-content-tertiary size-5" />
                        )}
                      </span>
                      <span className="flex min-w-0 flex-1 flex-col">
                        <span className="text-content truncate text-sm">{ad.name}</span>
                        <span className="text-content-tertiary text-xs">
                          {ad.ctr_now === null
                            ? '—'
                            : t('hierarchy.ctr', { value: (ad.ctr_now * 100).toFixed(2) })}
                          {ad.frequency === null
                            ? null
                            : ` · ${t('hierarchy.frequency', { value: ad.frequency.toFixed(1) })}`}
                        </span>
                      </span>
                      {ad.ctr_change === null ? null : (
                        <span
                          className={cn(
                            'ds-numeric shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold',
                            tiring
                              ? 'bg-warning-surface text-warning'
                              : 'bg-surface-sunken text-content-secondary',
                          )}
                        >
                          {tiring ? `${t('hierarchy.tiring')} ` : ''}
                          {ad.ctr_change > 0 ? '+' : '−'}
                          {Math.abs(Math.round(ad.ctr_change * 100))}%
                        </span>
                      )}
                    </li>
                  );
                })}
              </ul>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
