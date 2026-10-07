import { AlertTriangle, CircleHelp, CircleSlash, Clock, ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { cn } from '@/shared/lib/cn';
import type { SpecReport, SpecVerdictValue } from './types';

type SpecPanelProps = {
  report: SpecReport | null;
};

/** The icon and tone each verdict wears. Status colours, paired with an icon and a label, never alone. */
const VERDICT: Record<
  SpecVerdictValue,
  { tone: 'success' | 'danger' | 'warning' | 'neutral'; icon: typeof ShieldCheck }
> = {
  pass: { tone: 'success', icon: ShieldCheck },
  fail: { tone: 'danger', icon: AlertTriangle },
  unchecked: { tone: 'warning', icon: CircleHelp },
  no_spec: { tone: 'neutral', icon: CircleSlash },
  stale: { tone: 'warning', icon: Clock },
};

/**
 * What checking this file against the placement's recorded specification established.
 *
 * The panel's whole job is to stop "valid" being said about something nobody measured. So it shows the verdict,
 * **both numbers for every check**, and the page the requirement was read on with the date somebody read it.
 *
 * `no_spec` is the commonest outcome and is presented as a fact about this product rather than a problem with
 * the file: the platforms do not publish their creative requirements in a form a program can read, so most
 * placements have none recorded. Saying so tells an agency which of their work goes out unchecked.
 */
export function SpecPanel({ report }: SpecPanelProps) {
  const { t } = useTranslation();

  if (report === null) {
    return null;
  }

  const { tone, icon: Icon } = VERDICT[report.verdict];

  return (
    <div className="border-line-subtle flex flex-col gap-3 rounded-lg border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="flex flex-wrap items-center gap-2">
          <Badge tone={tone}>
            <Icon aria-hidden className="size-3" />
            {report.verdict_label}
          </Badge>
          <span className="text-content-secondary text-xs">
            {report.provider} · {report.placement}
          </span>
        </span>

        {/*
          Only a measured failure stops a publish. Blocking on "not checked" would make this product's own gaps
          into the user's problem, and they would learn to click past the warning.
        */}
        {report.blocks_publishing ? <Badge tone="danger">{t('creative.spec.blocks')}</Badge> : null}
      </div>

      {report.verdict === 'no_spec' ? (
        <p className="text-content-secondary text-xs leading-relaxed">
          {t('creative.spec.noSpec')}
        </p>
      ) : null}

      {report.checks.length === 0 ? null : (
        <ul className="flex flex-col gap-1.5">
          {report.checks.map((check) => {
            const detail = VERDICT[check.verdict];

            return (
              <li
                key={check.requirement}
                className="flex flex-wrap items-baseline justify-between gap-2 text-xs"
              >
                <span className="flex items-center gap-1.5">
                  <detail.icon
                    aria-hidden
                    className={cn(
                      'size-3.5 shrink-0',
                      check.verdict === 'pass' && 'text-success',
                      check.verdict === 'fail' && 'text-danger',
                      (check.verdict === 'unchecked' || check.verdict === 'stale') &&
                        'text-warning',
                    )}
                  />
                  <span className="text-content">{check.requirement_label}</span>
                </span>

                {/*
                  Both numbers. "1350 against at least 1920 px" is something a producer fixes; "fails height"
                  is something they ask somebody about.
                */}
                <span className="text-content-secondary tabular-nums">
                  {check.measured === null ? t('creative.spec.notMeasured') : check.measured}
                  {check.expected === null ? '' : ` · ${check.expected}`}
                </span>

                {check.detail === null ? null : (
                  <span className="text-content-tertiary basis-full">{check.detail}</span>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {/*
        The page and the date. A specification nobody can trace is folklore, and one nobody has re-read for a
        year is not evidence about today's platform — which is why the age is shown rather than implied.
      */}
      {report.source === null ? null : (
        <p className="text-content-tertiary text-xs leading-relaxed">
          {t('creative.spec.source', {
            date: report.source.documented_at,
            days: report.spec_age_days ?? 0,
          })}{' '}
          <a
            href={report.source.url}
            target="_blank"
            rel="noreferrer noopener"
            className="text-content-brand underline"
          >
            {t('creative.spec.openSource')}
          </a>
          {report.source.note === null ? null : ` — ${report.source.note}`}
        </p>
      )}
    </div>
  );
}
