import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  ChevronDown,
  ChevronRight,
  Image as ImageIcon,
  PauseCircle,
  PlayCircle,
  Video,
} from 'lucide-react';
import { useSession } from '@/features/auth';
import { api } from '@/shared/api/client';
import { compactMoney } from '@/shared/lib/compact-money';
import { cn } from '@/shared/lib/cn';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { StatusRequestDialog } from './QuickActions';
import { useCampaignHierarchy, type Level } from './hierarchyData';

type BulkAction = 'request_pause' | 'request_resume' | 'create_task' | 'add_to_rule' | 'tag';

/** Click-through change past which an ad is shown as tiring. The Creative agent uses the same line. */
const TIRING = -0.25;
const RUNNING = new Set(['active', 'not_delivering', 'in_review']);

/**
 * Campaign → ad groups → ads, and the creatives they run, opened in place.
 *
 * No separate page per level: a campaign opens into its ad groups, each with its ads — the creative's own preview, its
 * click-through trend and how often the same people saw it — and the campaign's creatives listed once each, with the
 * ads that run them. Asking to pause or resume an ad group or an ad is offered where this campaign's platform documents
 * that change at that level, and shown disabled with the platform's reason where it does not. Several can be chosen
 * at once: asked about, given a task, added to a rule's scope or tagged — each through its own use case.
 */
export function Hierarchy({
  campaignId,
  defaultOpen = false,
}: {
  campaignId: string;
  defaultOpen?: boolean;
}) {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(defaultOpen);
  const [asking, setAsking] = useState<{
    level: Level;
    id: string;
    name: string;
    action: 'pause' | 'resume';
  } | null>(null);
  const [picked, setPicked] = useState<{ level: Level; ids: Set<string> }>({
    level: 'ad_group',
    ids: new Set(),
  });
  const [bulkAction, setBulkAction] = useState<BulkAction>('request_pause');
  const [reason, setReason] = useState('');
  const [target, setTarget] = useState('');

  const mayAsk = can('approvals.request');
  const mayBulk = mayAsk || can('tasks.manage');

  const query = useCampaignHierarchy(campaignId, open);

  const rules = useQuery({
    queryKey: ['automation', 'rules'] as const,
    queryFn: async () =>
      (
        await api.get<{ data: Array<{ id: string; name: string; object_level: string }> }>(
          '/automation/rules',
        )
      ).data.data,
    enabled: open && picked.ids.size > 0 && bulkAction === 'add_to_rule',
  });
  const tags = useQuery({
    queryKey: ['tags'] as const,
    queryFn: async () =>
      (await api.get<{ data: Array<{ id: string; name: string }> }>('/tags')).data.data,
    enabled: open && picked.ids.size > 0 && bulkAction === 'tag',
  });

  const bulk = useMutation({
    mutationFn: async (): Promise<{ done: number; refused: number }> => {
      const ids = [...picked.ids];

      if (bulkAction === 'tag') {
        await api.post(`/tags/${target}/entities/${picked.level}`, { ids });

        return { done: ids.length, refused: 0 };
      }

      const { data } = await api.post<{ data: { done: number; refused: number } }>(
        '/campaigns/entities/bulk',
        {
          level: picked.level,
          ids,
          action: bulkAction,
          ...(bulkAction === 'add_to_rule' ? { rule_id: target } : { reason: reason.trim() }),
        },
      );

      return data.data;
    },
    onSuccess: async () => {
      setPicked({ level: picked.level, ids: new Set() });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['approvals'] }),
        queryClient.invalidateQueries({ queryKey: ['automation'] }),
      ]);
    },
  });

  const money = (minor: number | null, currency: string | null) =>
    minor === null || currency === null ? '—' : compactMoney(minor, currency, i18n.language);

  const toggle = (level: Level, id: string) =>
    setPicked((current) => {
      const ids = new Set(current.level === level ? current.ids : []);

      if (ids.has(id)) ids.delete(id);
      else ids.add(id);

      return { level, ids };
    });

  /** The ask control for one entity: pause while it runs, resume while paused — or disabled, with the reason. */
  const ask = (level: Level, id: string, name: string, status: string) => {
    const action = RUNNING.has(status) ? 'pause' : status === 'paused' ? 'resume' : null;

    if (action === null || !mayAsk) return null;

    const write = query.data?.writes[level][action];
    const label = t(action === 'pause' ? 'hierarchy.askPause' : 'hierarchy.askResume');
    const Icon = action === 'pause' ? PauseCircle : PlayCircle;

    if (write?.support !== 'documented') {
      const because = write?.because ?? t('hierarchy.notOffered');

      return (
        <button
          type="button"
          disabled
          title={because}
          aria-label={`${label} — ${because}`}
          className="text-content-tertiary cursor-not-allowed rounded-full p-1 opacity-60"
        >
          <Icon aria-hidden className="size-4" />
        </button>
      );
    }

    return (
      <button
        type="button"
        title={label}
        aria-label={`${label}: ${name}`}
        onClick={() => setAsking({ level, id, name, action })}
        className="text-content-secondary hover:text-content rounded-full p-1"
      >
        <Icon aria-hidden className="size-4" />
      </button>
    );
  };

  const checkbox = (level: Level, id: string, name: string) =>
    mayBulk ? (
      <input
        type="checkbox"
        aria-label={t('hierarchy.choose', { name })}
        checked={picked.level === level && picked.ids.has(id)}
        onChange={() => toggle(level, id)}
        className="size-4 shrink-0 accent-current"
      />
    ) : null;

  const needsTarget = bulkAction === 'add_to_rule' || bulkAction === 'tag';
  const ready = needsTarget ? target !== '' : reason.trim().length >= 3;

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
        <>
          {picked.ids.size === 0 ? null : (
            <div
              role="region"
              aria-label={t('hierarchy.bulk.title')}
              className="bg-surface-sunken flex flex-wrap items-center gap-2 rounded-2xl p-3 text-sm"
            >
              <span className="font-semibold">
                {t(`hierarchy.bulk.chosen_${picked.level}`, { count: picked.ids.size })}
              </span>
              <select
                aria-label={t('hierarchy.bulk.action')}
                value={bulkAction}
                onChange={(e) => {
                  setBulkAction(e.target.value as BulkAction);
                  setTarget('');
                }}
                className="bg-surface h-9 rounded-full px-3 text-sm"
              >
                {(
                  [
                    ...(mayAsk ? (['request_pause', 'request_resume'] as const) : []),
                    ...(can('tasks.manage') ? (['create_task'] as const) : []),
                    'add_to_rule',
                    'tag',
                  ] as const
                ).map((a) => (
                  <option key={a} value={a}>
                    {t(`hierarchy.bulk.${a}`)}
                  </option>
                ))}
              </select>
              {needsTarget ? (
                <select
                  aria-label={t(
                    bulkAction === 'tag' ? 'hierarchy.bulk.tag' : 'hierarchy.bulk.rule',
                  )}
                  value={target}
                  onChange={(e) => setTarget(e.target.value)}
                  className="bg-surface h-9 max-w-56 rounded-full px-3 text-sm"
                >
                  <option value="">—</option>
                  {(bulkAction === 'tag'
                    ? (tags.data ?? [])
                    : (rules.data ?? []).filter((r) => r.object_level === picked.level)
                  ).map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
                </select>
              ) : (
                <Input
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder={t('hierarchy.bulk.why')}
                  aria-label={t('hierarchy.bulk.why')}
                  className="h-9 max-w-64"
                  dir="auto"
                />
              )}
              <Button size="sm" disabled={!ready || bulk.isPending} onClick={() => bulk.mutate()}>
                {t('hierarchy.bulk.apply')}
              </Button>
              {bulk.data === undefined ? null : (
                <span role="status" className="text-content-secondary text-xs">
                  {t('hierarchy.bulk.result', {
                    done: bulk.data.done,
                    refused: bulk.data.refused,
                  })}
                </span>
              )}
            </div>
          )}

          <ul className="flex flex-col gap-2" aria-label={t('hierarchy.groups')}>
            {query.data.groups.map((group) => (
              <li key={group.id} className="bg-surface-sunken flex flex-col gap-2 rounded-2xl p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="flex min-w-0 items-center gap-2">
                    {checkbox('ad_group', group.id, group.name)}
                    <span className="flex min-w-0 flex-col">
                      <Link
                        to={`/ad-groups/${group.id}`}
                        className="text-content truncate text-sm font-semibold hover:underline"
                        dir="auto"
                      >
                        {group.name}
                      </Link>
                      {group.audience === null ? null : (
                        <span className="text-content-tertiary truncate text-xs" dir="auto">
                          {group.audience}
                        </span>
                      )}
                    </span>
                  </span>
                  <span className="flex items-center gap-2 text-xs">
                    <span className="ds-numeric text-content font-semibold">
                      {money(group.spend_minor, group.currency)}
                    </span>
                    {group.budget_minor === null ? null : (
                      <span className="text-content-tertiary">
                        {t('hierarchy.daily', {
                          amount: money(group.budget_minor, group.currency),
                        })}
                      </span>
                    )}
                    {group.status === 'paused' ? (
                      <span className="bg-surface text-content-secondary rounded-full px-2 py-0.5">
                        {t('hierarchy.paused')}
                      </span>
                    ) : null}
                    {ask('ad_group', group.id, group.name, group.status)}
                  </span>
                </div>

                <ul
                  className="flex flex-col gap-1.5"
                  aria-label={t('hierarchy.adsIn', { name: group.name })}
                >
                  {group.ads.map((ad) => {
                    const tiring = ad.ctr_change !== null && ad.ctr_change <= TIRING;

                    return (
                      <li key={ad.id} className="bg-surface flex items-center gap-3 rounded-xl p-2">
                        {checkbox('ad', ad.id, ad.name)}
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
                          <Link
                            to={`/ads/${ad.id}`}
                            className="text-content truncate text-sm hover:underline"
                            dir="auto"
                          >
                            {ad.name}
                          </Link>
                          <span className="text-content-tertiary truncate text-xs">
                            {ad.creative === null ? null : (
                              <span dir="auto">{`${ad.creative.name} · `}</span>
                            )}
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
                        {ask('ad', ad.id, ad.name, ad.status)}
                      </li>
                    );
                  })}
                </ul>
              </li>
            ))}
          </ul>

          {query.data.creatives.length === 0 ? null : (
            <section aria-label={t('hierarchy.creatives')} className="flex flex-col gap-1.5">
              <h4 className="text-content-secondary text-xs font-semibold">
                {t('hierarchy.creatives')}
              </h4>
              <ul className="grid gap-1.5 sm:grid-cols-2">
                {query.data.creatives.map((creative) => (
                  <li
                    key={creative.id}
                    className="bg-surface-sunken flex items-center gap-2 rounded-xl p-2"
                  >
                    <span className="bg-surface-active grid size-9 shrink-0 place-items-center overflow-hidden rounded-lg">
                      {creative.thumbnail_url ? (
                        <img
                          src={creative.thumbnail_url}
                          alt=""
                          className="size-full object-cover"
                          loading="lazy"
                        />
                      ) : creative.format === 'video' ? (
                        <Video aria-hidden className="text-content-tertiary size-4" />
                      ) : (
                        <ImageIcon aria-hidden className="text-content-tertiary size-4" />
                      )}
                    </span>
                    <span className="flex min-w-0 flex-col">
                      <span className="text-content truncate text-sm" dir="auto">
                        {creative.name}
                      </span>
                      <span
                        className="text-content-tertiary truncate text-xs"
                        title={creative.ad_names.join(', ')}
                      >
                        {t('hierarchy.usedBy', { count: creative.ads })}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </>
      )}

      {asking === null ? null : (
        <StatusRequestDialog
          target={{
            path: `/campaigns/entities/${asking.level}/${asking.id}/status-request`,
            name: asking.name,
          }}
          action={asking.action}
          onClose={() => setAsking(null)}
        />
      )}
    </div>
  );
}
