import { Info, MessageSquare, ShoppingBag } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatMinor, formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import type { Journey, Touchpoint } from './types';

type JourneyPanelProps = {
  journey: Journey | undefined;
  isLoading: boolean;
  leadName: string | null;
};

/**
 * Everything recorded for one person, in order.
 *
 * A timeline rather than a chart, and that is the honest form: there are usually two or
 * three records, and two points do not make a trend. Drawing them on an axis would imply a
 * rate of something.
 *
 * Three refusals are built into this panel, all of them mirroring the server's:
 *
 *  - **A single record is not drawn as a journey.** A one-step timeline reads as a complete
 *    picture — this customer appeared from nowhere and bought — and a reader acts on that by
 *    concluding the channel that produced the enquiry deserves no credit. Both may be false.
 *  - **The journey has no campaign of its own.** Each touchpoint keeps the attribution *it*
 *    carried; deciding which touch earned the credit is a model, and first-touch, last-touch
 *    and linear disagree with each other by design.
 *  - **What is missing is stated.** The marketing before a conversion is absent from this
 *    product's data rather than nonexistent, and a reader has to know which.
 */
export function JourneyPanel({ journey, isLoading, leadName }: JourneyPanelProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('leads.journey.title')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </CardContent>
      </Card>
    );
  }

  if (journey === undefined) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('leads.journey.title')}</CardTitle>
        </CardHeader>
        <CardContent>
          <EmptyState
            title={t('leads.journey.pickTitle')}
            description={t('leads.journey.pickBody')}
          />
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{journey.name ?? leadName ?? t('leads.journey.title')}</CardTitle>
          {/*
            How sure we are these records are one person, and whether that is solid enough to
            count in a report or only to show somebody who can judge it. A shared-device click
            match is strong about the click and weak about the person.
          */}
          <Badge tone={journey.is_reliable ? 'neutral' : 'warning'}>
            {journey.identity_evidence_label} · {formatNumber(journey.identity_confidence, locale)}%
          </Badge>
        </div>
        <p className="text-content-secondary text-sm">
          {journey.is_multi_touch
            ? t('leads.journey.span', {
                count: journey.touchpoint_count,
                days: journey.span_in_days ?? 0,
              })
            : t('leads.journey.singleTouch')}
        </p>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {journey.earliest_attributed_touchpoint === null ? null : (
          <Alert tone="info">
            {/*
              Named as a fact about that record, not as "the journey's campaign". There is a
              test on the server asserting the response carries no such field.
            */}
            {t('leads.journey.earliest', {
              campaign:
                journey.earliest_attributed_touchpoint.campaign ??
                journey.earliest_attributed_touchpoint.source ??
                '—',
              record: journey.earliest_attributed_touchpoint.kind_label,
            })}
          </Alert>
        )}

        {journey.touchpoints.length === 0 ? (
          <EmptyState
            title={t('leads.journey.emptyTitle')}
            description={t('leads.journey.emptyBody')}
          />
        ) : (
          <ol className="flex flex-col gap-0">
            {journey.touchpoints.map((touchpoint, index) => (
              <TouchpointRow
                key={`${touchpoint.kind}-${touchpoint.id}`}
                touchpoint={touchpoint}
                isLast={index === journey.touchpoints.length - 1}
                locale={locale}
              />
            ))}
          </ol>
        )}

        {journey.limitations.length === 0 ? null : (
          <div className="border-line-subtle flex flex-col gap-1.5 rounded-lg border border-dashed px-4 py-3">
            <p className="text-content-secondary flex items-center gap-1.5 text-xs font-medium">
              <Info aria-hidden className="size-3.5" />
              {t('leads.journey.limitations')}
            </p>
            <ul className="text-content-tertiary flex flex-col gap-1 text-xs leading-relaxed">
              {journey.limitations.map((limitation) => (
                <li key={limitation}>{limitation}</li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function TouchpointRow({
  touchpoint,
  isLast,
  locale,
}: {
  touchpoint: Touchpoint;
  isLast: boolean;
  locale: string;
}) {
  const { t } = useTranslation();
  const Icon = touchpoint.kind === 'order' ? ShoppingBag : MessageSquare;

  return (
    <li className="flex gap-3">
      {/* The rail: a dot per record and a line between them, drawn in the chrome colours. */}
      <div className="flex flex-col items-center">
        <span
          aria-hidden
          className="border-line bg-surface text-content-secondary flex size-7 shrink-0 items-center justify-center rounded-full border"
        >
          <Icon className="size-3.5" />
        </span>
        {isLast ? null : <span aria-hidden className="bg-line-subtle w-px flex-1" />}
      </div>

      <div className={isLast ? 'flex flex-col gap-1 pb-0' : 'flex flex-col gap-1 pb-5'}>
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-content text-sm font-medium">{touchpoint.summary}</span>
          {touchpoint.state === null ? null : <Badge tone="neutral">{touchpoint.state}</Badge>}
        </div>
        <p className="text-content-tertiary text-xs tabular-nums">
          {new Date(touchpoint.occurred_at).toLocaleString(locale, {
            dateStyle: 'medium',
            timeStyle: 'short',
          })}
          {touchpoint.value_minor === null || touchpoint.currency === null
            ? ''
            : ` · ${formatMinor(touchpoint.value_minor, touchpoint.currency, locale)}`}
        </p>
        <p className="text-content-secondary text-xs">
          {/*
            What this record itself carried, never inherited from a neighbour. An enquiry
            tagged `autumn` and an order tagged nothing stay that way: letting the order
            inherit it would manufacture a link the order never had, which is precisely how
            an attributed revenue figure becomes indefensible.
          */}
          {touchpoint.campaign === null && touchpoint.source === null
            ? t('leads.journey.noAttribution')
            : t('leads.journey.carried', {
                campaign: touchpoint.campaign ?? touchpoint.source ?? '—',
                evidence: touchpoint.attribution_evidence_label,
              })}
        </p>
      </div>
    </li>
  );
}
