import { useTranslation } from 'react-i18next';
import { useStoreAnalyticsQuery } from '@/features/commerce';
import { formatMinor } from '@/shared/lib/format';
import { formatMetric, MISSING } from '@/shared/metrics';
import type { AnalyticsPayload } from '@/shared/metrics';
import { InfoHint } from '@/shared/ui/info-hint';

/**
 * Who says what the revenue was, side by side and never added.
 *
 * The ad platforms report what they believe they caused; a store counts what it invoiced; an analytics property
 * counts what a browser reported. They measure different things and they will disagree, so each is its own card with
 * its own name — there is no total, and nothing here sums two of them. An authority with nothing to say for this
 * client and period is left out rather than shown as zero.
 *
 * Shown only for one client: across the whole agency the stores are many, the currencies are several, and a
 * comparison of the sum of the platforms with the sum of the stores would compare two things that were never one thing.
 */
export function AttributionPanel({
  data,
  clientId,
}: {
  data: AnalyticsPayload;
  clientId: string | null;
}) {
  const { t, i18n } = useTranslation();
  const range = { from: data.range.start, until: data.range.end };
  const enabled = clientId !== null;

  const salla = useStoreAnalyticsQuery(
    { revenue_source: 'salla', client_id: clientId, ...range },
    enabled,
  );
  const zid = useStoreAnalyticsQuery(
    { revenue_source: 'zid', client_id: clientId, ...range },
    enabled,
  );
  const ga4 = useStoreAnalyticsQuery(
    { revenue_source: 'google_analytics', client_id: clientId, ...range },
    enabled,
  );

  if (!enabled) {
    return null;
  }

  const stores = [
    ['salla', salla.data],
    ['zid', zid.data],
    ['google_analytics', ga4.data],
  ] as const;
  const cards = stores.flatMap(([source, answer]) =>
    answer === undefined || answer.summary.orders === 0
      ? []
      : [
          {
            key: source,
            label: t(`commerce.sources.${source}`),
            tone: answer.summary.is_invoiced ? 'invoiced' : 'measured',
            value: formatMinor(
              answer.summary.net_revenue_minor,
              answer.summary.currency,
              i18n.language,
            ),
          },
        ],
  );

  const platform = data.summary.values['revenue'];

  if (platform === undefined && cards.length === 0) {
    return null;
  }

  return (
    <section aria-label={t('analytics.attribution.title')} className="flex flex-col gap-3">
      <h2 className="text-content flex items-center gap-1.5 text-[0.9375rem] font-semibold">
        {t('analytics.attribution.title')}
        <InfoHint text={t('analytics.attribution.hint')} />
      </h2>
      <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {platform === undefined ? null : (
          <Card
            label={t('commerce.sources.platform_reported')}
            tone="claimed"
            toneLabel={t('analytics.attribution.claimed')}
            value={platform.value === null ? MISSING : formatMetric(platform, i18n.language)}
          />
        )}
        {cards.map((card) => (
          <Card
            key={card.key}
            label={card.label}
            tone={card.tone}
            toneLabel={t(`analytics.attribution.${card.tone}`)}
            value={card.value}
          />
        ))}
      </ul>
    </section>
  );
}

function Card({
  label,
  value,
  tone,
  toneLabel,
}: {
  label: string;
  value: string;
  tone: string;
  toneLabel: string;
}) {
  return (
    <li
      data-authority={tone}
      className="border-line-subtle bg-surface flex min-w-0 flex-col gap-1 rounded-2xl border p-4"
    >
      <span className="text-content-tertiary ds-overline truncate">{label}</span>
      <span className="ds-figure text-content text-2xl [overflow-wrap:anywhere]">{value}</span>
      <span className="text-content-secondary text-xs">{toneLabel}</span>
    </li>
  );
}
