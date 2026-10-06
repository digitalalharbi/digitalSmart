import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, X } from 'lucide-react';
import { useMetricCatalogue } from '@/features/analytics';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Input } from '@/shared/ui/input';
import { MAX_HEIGHT, MIN_HEIGHT, WIDTH_PRESETS, clampHeight, draftKey } from './layout';
import type { WidgetDraft, WidgetTypeValue } from './types';
import type { DimensionValue } from '@/shared/metrics';

/** The types a reader can choose, and what each one needs to work. */
const TYPES: readonly { value: WidgetTypeValue; needsDimension: boolean; metrics: number }[] = [
  { value: 'scorecard', needsDimension: false, metrics: 1 },
  { value: 'line', needsDimension: false, metrics: 2 },
  { value: 'bar', needsDimension: true, metrics: 1 },
  { value: 'share', needsDimension: true, metrics: 1 },
  { value: 'scatter', needsDimension: true, metrics: 2 },
  { value: 'table', needsDimension: true, metrics: 6 },
];

/** Dimensions a widget can group by. Date is only meaningful for a trend. */
const DIMENSIONS: readonly DimensionValue[] = [
  'provider',
  'campaign',
  'ad_group',
  'ad',
  'external_account',
];

type WidgetEditorProps = {
  /** The widget being changed, or undefined when adding a new one. */
  widget?: WidgetDraft;
  onSave: (widget: WidgetDraft) => void;
  onCancel: () => void;
};

/**
 * Compose one widget.
 *
 * The controls constrain each other, which is the point: choosing a share-of-total
 * removes Date from the groupings, and choosing a scatter asks for a second metric.
 * The server validates the same rules, but discovering them by being refused on save
 * is a worse way to learn them than never being offered the invalid combination.
 */
export function WidgetEditor({ widget, onSave, onCancel }: WidgetEditorProps) {
  const { t } = useTranslation();
  const catalogue = useMetricCatalogue();

  const [type, setType] = useState<WidgetTypeValue>(widget?.type ?? 'bar');
  const [metrics, setMetrics] = useState<string[]>(widget?.metrics ?? ['spend']);
  const [dimension, setDimension] = useState<DimensionValue | null>(
    widget?.dimension ?? 'campaign',
  );
  const [title, setTitle] = useState(widget?.title ?? '');
  const [width, setWidth] = useState(widget?.width ?? 6);
  const [height, setHeight] = useState(widget?.height ?? 2);

  const spec = useMemo(() => TYPES.find((entry) => entry.value === type) ?? TYPES[2]!, [type]);

  // A trend groups by date implicitly; a share or scatter across dates is meaningless.
  const availableDimensions = useMemo(
    () => (type === 'line' || type === 'scorecard' ? [] : DIMENSIONS),
    [type],
  );

  const metricOptions = useMemo(() => catalogue.data?.metrics ?? [], [catalogue.data]);

  const changeType = (next: WidgetTypeValue) => {
    setType(next);

    const nextSpec = TYPES.find((entry) => entry.value === next);

    if (nextSpec === undefined) {
      return;
    }

    // Trim metrics the new type cannot use, rather than silently sending extras the
    // server would reject.
    setMetrics((current) => current.slice(0, nextSpec.metrics));

    if (!nextSpec.needsDimension) {
      setDimension(null);
    } else if (dimension === null) {
      setDimension('campaign');
    }
  };

  const toggleMetric = (metric: string) => {
    setMetrics((current) => {
      if (current.includes(metric)) {
        // Never leave a widget with nothing to show.
        return current.length === 1 ? current : current.filter((value) => value !== metric);
      }

      return current.length >= spec.metrics
        ? // At the limit, the newest choice replaces the oldest.
          [...current.slice(1), metric]
        : [...current, metric];
    });
  };

  const needsSecondMetric = type === 'scatter' && metrics.length < 2;
  const needsDimension = spec.needsDimension && dimension === null;
  const canSave = metrics.length > 0 && !needsSecondMetric && !needsDimension;

  const submit = () => {
    if (!canSave) {
      return;
    }

    onSave({
      key: widget?.key ?? draftKey(),
      title: title.trim() === '' ? null : title.trim(),
      type,
      metrics,
      dimension: spec.needsDimension ? dimension : null,
      filter_overrides: widget?.filter_overrides ?? null,
      // Placement is settled by the canvas re-flow; these are the requested size.
      column: widget?.column ?? 0,
      row: widget?.row ?? 0,
      width,
      height: clampHeight(height),
      options: widget?.options ?? {},
    });
  };

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      <ChoiceGroup label={t('dashboards.editor.type')}>
        <div className="flex flex-wrap gap-1.5">
          {TYPES.map((entry) => (
            <Choice
              key={entry.value}
              isSelected={entry.value === type}
              onSelect={() => changeType(entry.value)}
            >
              {t(`dashboards.widgetTypes.${entry.value}`)}
            </Choice>
          ))}
        </div>
      </ChoiceGroup>

      <ChoiceGroup
        label={t('dashboards.editor.metrics')}
        hint={
          spec.metrics === 1
            ? t('dashboards.editor.oneMetric')
            : t('dashboards.editor.upToMetrics', { count: spec.metrics })
        }
      >
        <div className="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
          {metricOptions.map((metric) => (
            <Choice
              key={metric.value}
              isSelected={metrics.includes(metric.value)}
              onSelect={() => toggleMetric(metric.value)}
            >
              {metric.label}
            </Choice>
          ))}
        </div>
        {needsSecondMetric && (
          <p className="text-warning text-xs">{t('dashboards.editor.needsTwoMetrics')}</p>
        )}
      </ChoiceGroup>

      {availableDimensions.length > 0 && (
        <ChoiceGroup label={t('dashboards.editor.groupBy')}>
          <div className="flex flex-wrap gap-1.5">
            {availableDimensions.map((value) => (
              <Choice
                key={value}
                isSelected={value === dimension}
                onSelect={() => setDimension(value)}
              >
                {t(`analytics.dimensions.${value}`)}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <ChoiceGroup label={t('dashboards.editor.width')}>
          <div className="flex flex-wrap gap-1.5">
            {WIDTH_PRESETS.map((preset) => (
              <Choice key={preset} isSelected={preset === width} onSelect={() => setWidth(preset)}>
                {/* Expressed as a fraction of the canvas, which is what a reader is
                    actually choosing — not a column count. */}
                {t('dashboards.editor.widthFraction', { columns: preset, total: 12 })}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        <ChoiceGroup label={t('dashboards.editor.height')}>
          <div className="flex flex-wrap gap-1.5">
            {Array.from(
              { length: MAX_HEIGHT - MIN_HEIGHT + 1 },
              (_, index) => index + MIN_HEIGHT,
            ).map((rows) => (
              <Choice key={rows} isSelected={rows === height} onSelect={() => setHeight(rows)}>
                {rows}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>
      </div>

      <ChoiceGroup label={t('dashboards.editor.title')} hint={t('dashboards.editor.titleHint')}>
        <Input
          value={title}
          onChange={(event) => setTitle(event.target.value)}
          placeholder={t(`dashboards.widgetTypes.${type}`)}
          maxLength={120}
        />
      </ChoiceGroup>

      <div className="flex items-center justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onCancel}>
          <X aria-hidden className="size-4" />
          {t('common.cancel')}
        </Button>
        <Button type="submit" disabled={!canSave}>
          <Check aria-hidden className="size-4" />
          {widget === undefined ? t('dashboards.editor.add') : t('dashboards.editor.apply')}
        </Button>
      </div>
    </form>
  );
}
