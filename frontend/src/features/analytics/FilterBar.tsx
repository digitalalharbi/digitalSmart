import { useTranslation } from 'react-i18next';
import { CalendarDays, GitCompareArrows, Layers } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import type { ComparisonMode, DatePreset, DimensionValue } from '@/shared/metrics';

const PRESETS: readonly DatePreset[] = ['7d', '28d', '90d', 'this_month', 'last_month'];
const COMPARISONS: readonly ComparisonMode[] = ['none', 'previous_period', 'previous_year'];

type FilterBarProps = {
  preset: DatePreset;
  onPresetChange: (preset: DatePreset) => void;
  comparison: ComparisonMode;
  onComparisonChange: (comparison: ComparisonMode) => void;
  /*
   * `| undefined` is explicit on every optional prop because the build runs with
   * `exactOptionalPropertyTypes`: under that flag "absent" and "present but
   * undefined" are different types, and a caller passing a value that has not
   * loaded yet is passing the second.
   */
  dimension?: DimensionValue | undefined;
  onDimensionChange?: ((dimension: DimensionValue) => void) | undefined;
  dimensions?: readonly DimensionValue[] | undefined;
  /** Platform chips, when the surface supports filtering by one. */
  providers?: { value: string; label: string }[] | undefined;
  selectedProviders?: string[] | undefined;
  onProviderToggle?: ((provider: string) => void) | undefined;
  /** Dimmed while a refetch is in flight, rather than replaced by skeletons. */
  isRefreshing?: boolean;
};

/**
 * The global filters.
 *
 * Segmented controls rather than dropdowns for the choices a reader changes
 * constantly: a date range and a comparison are one click each here, where a
 * select would be three. Dropdowns are for long lists, not for five options.
 *
 * Everything is a real button with a pressed state, so the current selection is
 * legible without colour alone and reachable by keyboard.
 */
export function FilterBar({
  preset,
  onPresetChange,
  comparison,
  onComparisonChange,
  dimension,
  onDimensionChange,
  dimensions,
  providers,
  selectedProviders = [],
  onProviderToggle,
  isRefreshing = false,
}: FilterBarProps) {
  const { t } = useTranslation();

  return (
    <div
      className={cn(
        'bg-surface border-line-subtle flex flex-wrap items-center gap-x-5 gap-y-3 rounded-xl border px-4 py-3',
        // Dimmed, not hidden: a reader comparing two filters should keep their place.
        isRefreshing && 'opacity-70',
      )}
    >
      <Group
        icon={<CalendarDays aria-hidden className="size-3.5" />}
        label={t('analytics.filters.period')}
      >
        <Segmented
          options={PRESETS.map((value) => ({ value, label: t(`analytics.presets.${value}`) }))}
          selected={preset}
          onSelect={(value) => onPresetChange(value as DatePreset)}
        />
      </Group>

      <Group
        icon={<GitCompareArrows aria-hidden className="size-3.5" />}
        label={t('analytics.filters.compare')}
      >
        <Segmented
          options={COMPARISONS.map((value) => ({
            value,
            label: t(`analytics.comparisons.${value}`),
          }))}
          selected={comparison}
          onSelect={(value) => onComparisonChange(value as ComparisonMode)}
        />
      </Group>

      {dimensions !== undefined && dimension !== undefined && onDimensionChange !== undefined && (
        <Group
          icon={<Layers aria-hidden className="size-3.5" />}
          label={t('analytics.filters.groupBy')}
        >
          <Segmented
            options={dimensions.map((value) => ({
              value,
              label: t(`analytics.dimensions.${value}`),
            }))}
            selected={dimension}
            onSelect={(value) => onDimensionChange(value as DimensionValue)}
          />
        </Group>
      )}

      {providers !== undefined && providers.length > 0 && onProviderToggle !== undefined && (
        <Group label={t('analytics.filters.platforms')}>
          <div className="flex flex-wrap gap-1.5">
            {providers.map((provider) => {
              const isSelected = selectedProviders.includes(provider.value);

              return (
                <button
                  key={provider.value}
                  type="button"
                  onClick={() => onProviderToggle(provider.value)}
                  aria-pressed={isSelected}
                  className={cn(
                    'focus-visible:ring-accent/40 rounded-full border px-2.5 py-1 text-xs font-medium',
                    'ease-standard transition-colors duration-[var(--ds-duration-fast)] focus-visible:ring-2 focus-visible:outline-none',
                    isSelected
                      ? 'border-accent bg-accent-subtle text-content-brand'
                      : 'border-line text-content-secondary hover:bg-surface-hover',
                  )}
                >
                  <span
                    aria-hidden
                    className="me-1.5 inline-block size-1.5 rounded-full align-middle"
                    style={{
                      // The platform's colour appears only here, as a small
                      // identifier chip. It never colours a chart series or a
                      // surface: a dashboard of seven brand colours is a rainbow.
                      backgroundColor: `var(--ds-platform-${provider.value === 'google_ads' ? 'google' : provider.value})`,
                    }}
                  />
                  {provider.label}
                </button>
              );
            })}
          </div>
        </Group>
      )}
    </div>
  );
}

function Group({
  icon,
  label,
  children,
}: {
  icon?: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-content-tertiary flex items-center gap-1 text-xs font-medium">
        {icon}
        {label}
      </span>
      {children}
    </div>
  );
}

function Segmented({
  options,
  selected,
  onSelect,
}: {
  options: { value: string; label: string }[];
  selected: string;
  onSelect: (value: string) => void;
}) {
  return (
    <div
      role="group"
      className="bg-surface-sunken border-line-subtle inline-flex rounded-lg border p-0.5"
    >
      {options.map((option) => {
        const isSelected = option.value === selected;

        return (
          <button
            key={option.value}
            type="button"
            onClick={() => onSelect(option.value)}
            aria-pressed={isSelected}
            className={cn(
              'focus-visible:ring-accent/40 rounded-[0.3125rem] px-2.5 py-1 text-xs font-medium whitespace-nowrap',
              'ease-standard transition-all duration-[var(--ds-duration-fast)] focus-visible:ring-2 focus-visible:outline-none',
              isSelected
                ? 'bg-surface text-content shadow-xs'
                : 'text-content-secondary hover:text-content',
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}
