import { Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { useFunnelAnalyticsQuery } from '../funnels/api';
import { periodOfDays } from '../funnels/duration';
import { FunnelView } from '../funnels/FunnelView';
import { useLinksQuery, useSetLinkStatusMutation } from './api';
import { LinkBuilder } from './LinkBuilder';
import { LinkPerformancePanel } from './LinkPerformancePanel';
import { QrCode } from './QrCode';
import type { Counted, TrackingLink } from './types';

/** A count, or why there is none. Never a zero standing in for "we cannot know". */
function Figure({ label, counted }: { label: string; counted: Counted }) {
  const { t } = useTranslation();

  return (
    <div className="min-w-0">
      <dt className="text-content-tertiary text-xs">{label}</dt>
      <dd className="text-content text-lg font-semibold tabular-nums">
        {counted.value === null ? (
          <span title={counted.reason ?? undefined}>{t('tracking.links.notReported')}</span>
        ) : (
          counted.value.toLocaleString('en-US')
        )}
      </dd>
      {counted.value === null && counted.reason ? (
        <p className="text-warning mt-0.5 text-xs">{counted.reason}</p>
      ) : null}
    </div>
  );
}

/** The funnel for one link, read only when asked for: it is a heavier query than the counts above it. */
function LinkFunnel({ link }: { link: TrackingLink }) {
  const { t } = useTranslation();
  const funnel = useFunnelAnalyticsQuery(link.project.id, null, link.id, periodOfDays(30));

  return funnel.isPending ? (
    <Skeleton className="h-32 w-full" />
  ) : funnel.isError ? (
    <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
  ) : (
    <FunnelView funnel={funnel.data} />
  );
}

function LinkCard({ link, canManage }: { link: TrackingLink; canManage: boolean }) {
  const { t } = useTranslation();
  const setStatus = useSetLinkStatusMutation();
  const [copied, setCopied] = useState(false);
  const [showFunnel, setShowFunnel] = useState(false);
  const [showQr, setShowQr] = useState(false);
  const [showPerformance, setShowPerformance] = useState(false);
  const counts = link.counts;

  const copy = () => {
    void navigator.clipboard
      ?.writeText(link.short_url)
      .then(() => setCopied(true))
      .catch(() => setCopied(false));
  };

  return (
    <Card role="group" aria-label={link.name} className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="text-content truncate text-base font-semibold">{link.name}</h3>
          <p className="text-content-tertiary truncate text-xs">
            {link.client.name} · {link.project.name}
          </p>
        </div>
        <div className="flex flex-wrap gap-1.5">
          {link.platform ? (
            <Badge>
              {t(`tracking.links.platforms.${link.platform}`, { defaultValue: link.platform })}
            </Badge>
          ) : null}
          <Badge tone={link.status === 'active' ? 'success' : 'neutral'}>
            {t(`tracking.links.status.${link.status}`)}
          </Badge>
        </div>
      </header>

      <div className="bg-surface-sunken flex items-center gap-2 rounded-lg px-3 py-2">
        <code dir="ltr" className="text-content min-w-0 flex-1 truncate text-xs">
          {link.short_url}
        </code>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="max-md:h-11"
          onClick={copy}
          aria-label={t('tracking.links.copy', { name: link.name })}
        >
          <Copy aria-hidden="true" className="size-4" />
          <span className="sr-only sm:not-sr-only">
            {copied ? t('tracking.links.copied') : t('tracking.links.copyShort')}
          </span>
        </Button>
      </div>

      {counts === null ? null : (
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Figure label={t('tracking.links.clicks')} counted={counts.clicks} />
          <Figure label={t('tracking.links.visitors')} counted={counts.visitors} />
          <Figure label={t('tracking.links.landingViews')} counted={counts.landing_views} />
          <div className="min-w-0">
            <dt className="text-content-tertiary text-xs">{t('tracking.links.clickIds')}</dt>
            <dd className="text-content text-lg font-semibold tabular-nums">
              {counts.click_id_coverage === null
                ? t('tracking.links.notReported')
                : `${Math.round(counts.click_id_coverage * 100)}%`}
            </dd>
          </div>
        </dl>
      )}
      {counts !== null && counts.excluded > 0 ? (
        <p className="text-content-tertiary text-xs">
          {t('tracking.links.excluded', { count: counts.excluded })}
        </p>
      ) : null}

      {showPerformance ? <LinkPerformancePanel linkId={link.id} /> : null}
      {showQr ? <QrCode address={link.short_url} name={link.name} /> : null}
      {showFunnel ? <LinkFunnel link={link} /> : null}

      <div className="flex flex-wrap justify-end gap-2">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="max-md:h-11"
          aria-expanded={showPerformance}
          onClick={() => setShowPerformance((v) => !v)}
        >
          {showPerformance ? t('tracking.performance.hide') : t('tracking.performance.show')}
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="max-md:h-11"
          aria-expanded={showQr}
          onClick={() => setShowQr((v) => !v)}
        >
          {showQr ? t('tracking.links.hideQr') : t('tracking.links.showQr')}
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="max-md:h-11"
          aria-expanded={showFunnel}
          onClick={() => setShowFunnel((v) => !v)}
        >
          {showFunnel ? t('tracking.links.hideFunnel') : t('tracking.links.showFunnel')}
        </Button>
        {canManage ? (
          <Button
            type="button"
            variant="secondary"
            size="sm"
            className="max-md:h-11"
            disabled={setStatus.isPending || link.status === 'archived'}
            onClick={() =>
              setStatus.mutate({
                id: link.id,
                status: link.status === 'active' ? 'paused' : 'active',
              })
            }
          >
            {link.status === 'active' ? t('tracking.links.pause') : t('tracking.links.resume')}
          </Button>
        ) : null}
      </div>
    </Card>
  );
}

/** The Links tab: build a tracked address for an advertisement and see what it brought. */
export function LinksTab() {
  const { t } = useTranslation();
  const { can } = useSession();
  const links = useLinksQuery(null);
  const [building, setBuilding] = useState(false);
  const canManage = can(PERMISSIONS.trackingManage);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-content-secondary max-w-prose text-sm">{t('tracking.links.intro')}</p>
        {canManage ? (
          <Button type="button" onClick={() => setBuilding(true)}>
            {t('tracking.links.create')}
          </Button>
        ) : null}
      </div>

      {links.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : links.isError ? (
        <Alert tone="danger">{t('tracking.links.failed')}</Alert>
      ) : links.data.length === 0 ? (
        <EmptyState
          title={t('tracking.links.empty.title')}
          description={t('tracking.links.empty.body')}
        />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {links.data.map((link) => (
            <LinkCard key={link.id} link={link} canManage={canManage} />
          ))}
        </div>
      )}

      <LinkBuilder open={building} onOpenChange={setBuilding} />
    </div>
  );
}
