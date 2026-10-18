import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { AlertTriangle } from 'lucide-react';
import type { PortfolioClient } from '@/features/portfolio';
import { formatNumber } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { HealthRing } from '@/shared/ui/viz';
import { ClientRowActions } from './ClientRowActions';
import { StatusBadge } from './StatusBadge';
import type { Client } from './types';

function compact(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(minor / 100);
}

/**
 * One client, as a card that answers "how is this account doing?" before it is opened.
 *
 * The whole card is the link to the client; the row menu sits above it. What it carries is the account's picture:
 * its campaign health as a ring, what it spent, what it produced, and how many things need a person — all from the
 * portfolio read, so it can never disagree with the page it opens. A client the reader has no performance access to
 * gets the plain card (name, status, projects) rather than a row of dashes pretending to be data.
 */
export function ClientCard({
  client,
  figures,
  openWork = null,
  onEdit,
}: {
  client: Client;
  figures?: PortfolioClient | undefined;
  /** Open tasks across the client's projects, when the portfolio knows them. */
  openWork?: number | null;
  onEdit: (client: Client) => void;
}) {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);

  const initials = client.name
    .replace(/^DEMO\s+—\s+/u, '')
    .split(/\s+/u)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

  const spend = figures?.spend.slice().sort((a, b) => b.minor - a.minor)[0];
  const alerts =
    figures === undefined
      ? 0
      : figures.needs_attention + (figures.budget_risks ?? 0) + (figures.tracking_attention ?? 0);

  return (
    /*
      A named article, not an anonymous div. A card is a self-contained record, and naming it gives a screen
      reader something to announce on entry and a test something to address that is not a CSS class.
    */
    <Card
      role="article"
      aria-label={client.name}
      className="ease-standard relative transition-shadow duration-[var(--ds-duration-fast)] hover:shadow-md"
    >
      <CardContent className="flex flex-col gap-4 pt-6">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            {/*
              Initials, not a logo: nothing in this product stores a client's mark yet, and a generated
              avatar that looked like a brand asset would be a claim about something nobody uploaded.
            */}
            <span
              aria-hidden="true"
              className="bg-accent-subtle text-content-brand grid size-11 shrink-0 place-items-center rounded-2xl text-sm font-semibold"
            >
              {initials}
            </span>
            <div className="min-w-0">
              <Link
                to={`/clients/${client.id}`}
                className="text-content block truncate font-medium after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none"
              >
                {client.name}
              </Link>
              <p className="text-content-tertiary truncate text-xs">
                {client.industry ?? t('clients.card.noIndustry')}
              </p>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-1">
            {figures === undefined ? null : (
              <HealthRing
                counts={figures.campaign_health}
                size={44}
                thickness={6}
                label={t('overview.cc.health.title')}
              />
            )}
            <div className="relative z-10">
              <ClientRowActions client={client} onEdit={onEdit} />
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={client.status} />
          {client.is_demo ? <Badge tone="warning">{t('status.demo')}</Badge> : null}
          {alerts > 0 ? (
            <Badge tone="warning">
              <AlertTriangle aria-hidden className="size-3" />
              {t('clients.card.alerts', { count: alerts })}
            </Badge>
          ) : null}
        </div>

        <dl className="border-line-subtle flex flex-wrap items-end gap-x-5 gap-y-3 border-t pt-4">
          <div>
            <dt className="text-content-tertiary ds-overline">{t('clients.columns.projects')}</dt>
            <dd className="text-content ds-numeric text-xl font-semibold">
              {formatNumber(client.project_count, locale)}
            </dd>
          </div>
          {figures === undefined ? null : (
            <>
              <div>
                <dt className="text-content-tertiary ds-overline">{t('clients.card.spend')}</dt>
                <dd className="text-content ds-numeric text-xl font-semibold">
                  {spend === undefined ? '—' : compact(spend.minor, spend.currency, locale)}
                </dd>
              </div>
              <div>
                <dt className="text-content-tertiary ds-overline">
                  {figures.leads === null ? t('overview.cc.kpi.sales') : t('overview.cc.kpi.leads')}
                </dt>
                <dd className="text-content ds-numeric text-xl font-semibold">
                  {formatNumber(figures.leads ?? figures.sales, locale)}
                </dd>
              </div>
            </>
          )}
          {figures !== undefined || client.primary_contact_email === null ? null : (
            <div className="min-w-0 text-end">
              <dt className="text-content-tertiary ds-overline">{t('clients.card.contact')}</dt>
              <dd className="text-content-secondary truncate text-sm">
                {client.primary_contact_email}
              </dd>
            </div>
          )}
        </dl>
        {openWork === null || openWork === 0 ? null : (
          <p className="text-content-tertiary text-xs">
            {t('clients.card.openWork', { count: openWork })}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
