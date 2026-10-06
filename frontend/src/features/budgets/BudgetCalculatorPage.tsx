import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { TriangleAlert } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceBadge } from '@/shared/ui/source-badge';
import { formatCurrency, formatNumber } from '@/shared/lib/format';
import { cn } from '@/shared/lib/cn';
import { useForecastQuery } from './useBudgets';
import type { ConfidenceValue, ForecastInput, ForecastPayload, ProjectionPayload } from './types';

type Direction = 'budget' | 'target';

type TargetMetric = 'conversions' | 'clicks' | 'impressions';

const WINDOWS = [30, 90, 180] as const;

const TARGETS: readonly TargetMetric[] = ['conversions', 'clicks', 'impressions'];

const CONFIDENCE_TONE: Record<ConfidenceValue, 'neutral' | 'warning' | 'info' | 'success'> = {
  none: 'warning',
  low: 'warning',
  medium: 'info',
  high: 'success',
};

/**
 * The calculator, in both directions.
 *
 * What this page is really for is making an estimate *arguable*. Anyone can divide a
 * budget by a cost per click; the work is in showing where the rate came from, how many
 * days of delivery are behind it, how widely it varied, and what could not be worked out
 * at all. So every projection here is shown with its band, its confidence, its
 * assumptions in words, and the sample version — and a platform with too little data
 * shows a reason instead of a number.
 */
export function BudgetCalculatorPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canView = can(PERMISSIONS.budgetsView);

  const [direction, setDirection] = useState<Direction>('budget');
  const [budget, setBudget] = useState('50000');
  const [targetMetric, setTargetMetric] = useState<TargetMetric>('conversions');
  const [targetValue, setTargetValue] = useState('250');
  const [windowDays, setWindowDays] = useState<number>(90);
  const [submitted, setSubmitted] = useState<ForecastInput | null>(null);

  const locale = i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en';
  const forecast = useForecastQuery(canView ? submitted : null);

  const pending = useMemo<ForecastInput>(
    () =>
      direction === 'budget'
        ? { providers: [], budget, window_days: windowDays }
        : {
            providers: [],
            target_metric: targetMetric,
            target_value: Number(targetValue),
            window_days: windowDays,
          },
    [direction, budget, targetMetric, targetValue, windowDays],
  );

  if (!canView) {
    return (
      <EmptyState
        title={t('budgets.forbidden.title')}
        description={t('budgets.forbidden.description')}
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-content text-2xl font-semibold">{t('budgetCalculator.title')}</h1>
        <p className="text-content-secondary text-sm">{t('budgetCalculator.subtitle')}</p>
      </header>

      <Card className="flex flex-col gap-4 p-4">
        <ChoiceGroup label={t('budgetCalculator.direction.label')}>
          <div className="flex flex-wrap gap-1.5">
            {(['budget', 'target'] as const).map((value) => (
              <Choice
                key={value}
                isSelected={direction === value}
                onSelect={() => setDirection(value)}
              >
                {t(`budgetCalculator.direction.${value}`)}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {direction === 'budget' ? (
            <Field label={t('budgetCalculator.fields.budget')}>
              {(field) => (
                <Input
                  {...field}
                  inputMode="decimal"
                  value={budget}
                  onChange={(event) => setBudget(event.target.value)}
                />
              )}
            </Field>
          ) : (
            <>
              <ChoiceGroup label={t('budgetCalculator.fields.targetMetric')}>
                <div className="flex flex-wrap gap-1.5">
                  {TARGETS.map((metric) => (
                    <Choice
                      key={metric}
                      isSelected={targetMetric === metric}
                      onSelect={() => setTargetMetric(metric)}
                    >
                      {t(`budgetCalculator.targets.${metric}`)}
                    </Choice>
                  ))}
                </div>
              </ChoiceGroup>
              <Field label={t('budgetCalculator.fields.targetValue')}>
                {(field) => (
                  <Input
                    {...field}
                    inputMode="decimal"
                    value={targetValue}
                    onChange={(event) => setTargetValue(event.target.value)}
                  />
                )}
              </Field>
            </>
          )}

          <ChoiceGroup
            label={t('budgetCalculator.fields.window')}
            hint={t('budgetCalculator.fields.windowHint')}
          >
            <div className="flex flex-wrap gap-1.5">
              {WINDOWS.map((days) => (
                <Choice
                  key={days}
                  isSelected={windowDays === days}
                  onSelect={() => setWindowDays(days)}
                >
                  {t('budgetCalculator.fields.windowDays', { days })}
                </Choice>
              ))}
            </div>
          </ChoiceGroup>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <Button type="button" onClick={() => setSubmitted(pending)}>
            {t('budgetCalculator.estimate')}
          </Button>
          <p className="text-content-tertiary text-xs">{t('budgetCalculator.ownDataOnly')}</p>
        </div>
      </Card>

      {forecast.isPending && submitted !== null ? (
        <div className="grid gap-4 md:grid-cols-2">
          <Skeleton className="h-48" />
          <Skeleton className="h-48" />
        </div>
      ) : null}

      {forecast.isError ? <Alert tone="danger">{t('budgetCalculator.error')}</Alert> : null}

      {forecast.data !== undefined ? (
        <div className="flex flex-col gap-4">
          <Alert tone="info">{forecast.data.disclaimer}</Alert>

          <p className="text-content-tertiary text-xs">
            {t('budgetCalculator.sample', {
              from: forecast.data.benchmarks.window_start,
              to: forecast.data.benchmarks.window_end,
              version: forecast.data.benchmarks.version,
            })}
          </p>

          {forecast.data.forecasts.length === 0 ? (
            <EmptyState
              title={t('budgetCalculator.empty.title')}
              description={t('budgetCalculator.empty.description')}
            />
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {forecast.data.forecasts.map((item) => (
                <ForecastCard key={item.provider} forecast={item} locale={locale} />
              ))}
            </div>
          )}
        </div>
      ) : null}
    </div>
  );
}

function ForecastCard({ forecast, locale }: { forecast: ForecastPayload; locale: string }) {
  const { t } = useTranslation();
  const currency = forecast.currency ?? 'SAR';

  return (
    <Card className="flex flex-col gap-4 p-4">
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex flex-col gap-1">
          <h2 className="text-content text-base font-semibold">{forecast.provider_label}</h2>
          <p className="text-content-tertiary text-xs">
            {t('budgetCalculator.observedDays', { count: forecast.observed_days })}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={CONFIDENCE_TONE[forecast.confidence]}>{forecast.confidence_label}</Badge>
          {/* The projection is an estimate; the rates behind it carry their own label. */}
          <SourceBadge source={forecast.source} />
          <SourceBadge source={forecast.rate_source} />
        </div>
      </header>

      {forecast.projection === null ? (
        <Alert tone="warning" className="flex items-start gap-2">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>{forecast.unavailable_reason ?? t('budgetCalculator.noProjection')}</span>
        </Alert>
      ) : (
        <>
          <dl className="grid gap-3 sm:grid-cols-2">
            {forecast.projection.required_budget_minor !== null ? (
              <Figure
                label={t('budgetCalculator.figures.requiredBudget')}
                value={formatCurrency(
                  forecast.projection.required_budget_minor / 100,
                  currency,
                  locale,
                )}
                band={band(
                  forecast.projection.required_budget_low_minor,
                  forecast.projection.required_budget_high_minor,
                  (value) => formatCurrency(value / 100, currency, locale),
                )}
              />
            ) : null}
            <Figure
              label={t('budgetCalculator.figures.impressions')}
              value={count(forecast.projection.impressions, locale)}
              band={band(
                forecast.projection.impressions_low,
                forecast.projection.impressions_high,
                (value) => formatNumber(value, locale),
              )}
            />
            <Figure
              label={t('budgetCalculator.figures.clicks')}
              value={count(forecast.projection.clicks, locale)}
              band={band(forecast.projection.clicks_low, forecast.projection.clicks_high, (value) =>
                formatNumber(value, locale),
              )}
            />
            <Figure
              label={t('budgetCalculator.figures.conversions')}
              value={count(forecast.projection.conversions, locale, 1)}
              band={band(
                forecast.projection.conversions_low,
                forecast.projection.conversions_high,
                (value) => formatNumber(value, locale, 1),
              )}
            />
          </dl>

          <Assumptions projection={forecast.projection} />
        </>
      )}

      {forecast.gaps.length > 0 ? (
        <ul className="text-content-tertiary flex flex-col gap-1 text-xs">
          {forecast.gaps.map((gap) => (
            <li key={gap}>{gap}</li>
          ))}
        </ul>
      ) : null}
    </Card>
  );
}

function Figure({ label, value, band }: { label: string; value: string; band: string | null }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-content-tertiary text-xs font-medium">{label}</dt>
      <dd
        className={cn(
          'text-base font-semibold tabular-nums',
          value === '—' ? 'text-content-tertiary' : 'text-content',
        )}
      >
        {value}
      </dd>
      {band === null ? null : (
        <dd className="text-content-tertiary text-xs tabular-nums">{band}</dd>
      )}
    </div>
  );
}

function Assumptions({ projection }: { projection: ProjectionPayload }) {
  const { t } = useTranslation();

  if (projection.assumptions.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-1">
      <p className="text-content-tertiary text-xs font-medium">
        {t('budgetCalculator.assumptions')}
      </p>
      <ul className="text-content-secondary flex flex-col gap-0.5 text-xs">
        {projection.assumptions.map((assumption) => (
          <li key={assumption}>{assumption}</li>
        ))}
      </ul>
    </div>
  );
}

/**
 * A figure, or an em dash.
 *
 * Null means the rate behind it was never observed. A zero here would say "this budget
 * buys no clicks", which is a claim about a campaign rather than a statement about data.
 */
function count(value: number | null, locale: string, fractionDigits = 0): string {
  return value === null ? '—' : formatNumber(value, locale, fractionDigits);
}

/** The band, when both ends exist and differ from each other. */
function band(
  low: number | null,
  high: number | null,
  format: (value: number) => string,
): string | null {
  if (low === null || high === null || low === high) {
    return null;
  }

  return `${format(low)} – ${format(high)}`;
}
