import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import type { RecordedSpec } from './types';

type SpecCoverageProps = {
  specs: RecordedSpec[];
  isLoading: boolean;
};

/**
 * Which placements this product can check work against.
 *
 * A surface in its own right rather than a developer curiosity, because the **absent** list is the one that
 * matters: those are the placements an agency's creative goes out on without a safety net. The platforms do not
 * publish their requirements in a form a program can read — see docs/CREATIVE.md — so this list is short and
 * saying so is the honest thing to do.
 *
 * Not a chart. A list of covered placements with the page each was read from and its age is the form that
 * answers the question; a bar of "6 covered" against an unknown denominator would answer none.
 */
export function SpecCoverage({ specs, isLoading }: SpecCoverageProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('creative.coverage.title')}</CardTitle>
        <CardDescription>{t('creative.coverage.subtitle')}</CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-3">
        {/*
          Said first, because a reader who skims the list below will otherwise conclude that these are the only
          placements that exist rather than the only ones with a recorded requirement.
        */}
        <Alert tone="info">{t('creative.coverage.why')}</Alert>

        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : (
          <ul className="flex flex-col gap-2">
            {specs.map((spec) => (
              <li
                key={`${spec.provider}:${spec.placement}:${spec.kind}`}
                className="border-line-subtle flex flex-wrap items-baseline justify-between gap-2 border-b pb-2 text-sm last:border-0"
              >
                <span className="flex flex-wrap items-center gap-1.5">
                  <span className="text-content">
                    {spec.provider} · {spec.placement}
                  </span>
                  <Badge tone="neutral">{spec.kind}</Badge>
                  {/*
                    The age, shown rather than implied. A requirement nobody has re-read for six months is not
                    evidence about today's platform, and a check against it says so too.
                  */}
                  {spec.is_stale ? (
                    <Badge tone="warning">{t('creative.coverage.stale')}</Badge>
                  ) : null}
                </span>

                <span className="text-content-tertiary text-xs tabular-nums">
                  {t('creative.coverage.read', {
                    date: spec.source.documented_at,
                    days: formatNumber(spec.age_days, locale),
                  })}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
