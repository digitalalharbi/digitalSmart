import { cn } from '@/shared/lib/cn';

export type ProgressTone = 'good' | 'watch' | 'bad' | 'brand' | 'neutral';

const FILL: Record<ProgressTone, string> = {
  good: 'var(--ds-success-500)',
  watch: 'var(--ds-warning-500)',
  bad: 'var(--ds-danger-500)',
  brand: 'var(--ds-accent)',
  neutral: 'var(--ds-neutral-400)',
};

const clamp = (n: number): number => Math.min(100, Math.max(0, n));

/**
 * One figure against its limit, as a bar. The picture replaces a sentence: how much of the budget is spent, how far
 * a budget has paced, how complete a setup is. An optional `marker` draws where the figure *should* be (an even pace
 * through the period), so over- and under-pacing read at a glance without a word.
 *
 * Decorative by itself — the figure it shows sits beside it as text — so it is `aria-hidden` unless given a `label`,
 * in which case it is a named progress bar with its value.
 */
export function ProgressBar({
  value,
  max = 100,
  tone = 'brand',
  marker,
  label,
  height = 8,
  className,
}: {
  value: number;
  max?: number;
  tone?: ProgressTone;
  /** Where the figure is expected to be, on the same scale as `value`. */
  marker?: number | undefined;
  label?: string | undefined;
  height?: number;
  className?: string | undefined;
}) {
  const pct = max <= 0 ? 0 : clamp((value / max) * 100);
  const mark = marker === undefined || max <= 0 ? null : clamp((marker / max) * 100);

  return (
    <div
      {...(label === undefined
        ? { 'aria-hidden': true }
        : {
            role: 'progressbar',
            'aria-label': label,
            'aria-valuemin': 0,
            'aria-valuemax': 100,
            'aria-valuenow': Math.round(pct),
          })}
      className={cn('bg-surface-sunken relative w-full rounded-full', className)}
      style={{ height }}
    >
      <div
        className="ease-standard h-full rounded-full transition-[width] duration-[var(--ds-duration-base)]"
        style={{ width: `${String(pct)}%`, background: FILL[tone] }}
      />
      {mark === null ? null : (
        <span
          className="bg-content absolute top-[-3px] bottom-[-3px] w-0.5 rounded-full opacity-70"
          style={{ insetInlineStart: `calc(${String(mark)}% - 1px)` }}
        />
      )}
    </div>
  );
}

/** A single-figure ring: a share or a completion, the number in the middle. */
export function ProgressRing({
  value,
  max = 100,
  tone = 'brand',
  size = 56,
  thickness = 7,
  centre,
  label,
}: {
  value: number;
  max?: number;
  tone?: ProgressTone;
  size?: number;
  thickness?: number;
  /** What to print in the middle; defaults to the rounded percentage. */
  centre?: string;
  label: string;
}) {
  const pct = max <= 0 ? 0 : clamp((value / max) * 100);
  const r = (size - thickness) / 2;
  const c = 2 * Math.PI * r;

  return (
    <span
      role="img"
      aria-label={`${label}: ${String(Math.round(pct))}%`}
      className="relative inline-grid shrink-0 place-items-center"
      style={{ width: size, height: size }}
    >
      <svg
        width={size}
        height={size}
        viewBox={`0 0 ${String(size)} ${String(size)}`}
        aria-hidden="true"
        className="-rotate-90 rtl:rotate-90"
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke="var(--ds-surface-sunken)"
          strokeWidth={thickness}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={FILL[tone]}
          strokeWidth={thickness}
          strokeLinecap="round"
          strokeDasharray={c}
          strokeDashoffset={c * (1 - pct / 100)}
        />
      </svg>
      <span className="ds-numeric text-content absolute text-xs font-semibold">
        {centre ?? `${String(Math.round(pct))}%`}
      </span>
    </span>
  );
}

/**
 * Before and after, as two bars on one scale — what a decision card shows instead of "raise from X to Y". The change
 * between them is the pill; the bars carry the size of it.
 */
export function CompareBars({
  from,
  to,
  fromLabel,
  toLabel,
  format,
  tone,
}: {
  from: number;
  to: number;
  fromLabel: string;
  toLabel: string;
  format: (n: number) => string;
  /** The after-bar's tone; defaults to brand. */
  tone?: ProgressTone;
}) {
  const top = Math.max(from, to, 1);
  const delta = from === 0 ? null : Math.round(((to - from) / from) * 100);

  return (
    <div className="flex flex-col gap-2" dir="ltr">
      {(
        [
          [fromLabel, from, 'neutral'],
          [toLabel, to, tone ?? 'brand'],
        ] as const
      ).map(([name, value, barTone]) => (
        <div key={name} className="grid grid-cols-[4.5rem_1fr_auto] items-center gap-2 text-xs">
          <span className="text-content-tertiary truncate" dir="auto">
            {name}
          </span>
          <ProgressBar value={value} max={top} tone={barTone} height={10} />
          <span className="ds-numeric text-content font-semibold">{format(value)}</span>
        </div>
      ))}
      {delta === null ? null : (
        <span
          className={cn(
            'ds-numeric w-fit rounded-full px-2 py-0.5 text-xs font-semibold',
            delta === 0
              ? 'bg-surface-sunken text-content-secondary'
              : 'bg-accent-subtle text-content-brand',
          )}
        >
          {delta > 0 ? '+' : delta < 0 ? '−' : ''}
          {Math.abs(delta)}%
        </span>
      )}
    </div>
  );
}
