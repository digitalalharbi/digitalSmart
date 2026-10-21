import { cn } from '@/shared/lib/cn';
import { funnelSteps } from './funnel';

export type FunnelStage = {
  key: string;
  label: string;
  value: number | null;
  display: string;
  /**
   * How the count moved against the earlier period, already written. `good` says whether that direction is welcome, so
   * the colour is never a guess made here; null for a movement that is neither.
   */
  change?: { text: string; good: boolean | null } | undefined;
};

/**
 * A funnel: each stage a bar, narrowing, with the step rate and the drop-off beside it.
 *
 * A stage nobody measured is a dashed empty track saying so — never a short bar. A bar is a measurement, and
 * `null / top` is `0` in JavaScript: drawing it would read, to a client, as "this step almost never happened".
 * Scaled to the largest stage that *was* reported, not the first, because the first is the one that can be missing.
 */
export function FunnelBars({
  stages,
  className,
  label,
  notTracked,
  leakFrom,
  leakLabel,
}: {
  stages: FunnelStage[];
  className?: string | undefined;
  label: string;
  notTracked: string;
  /**
   * The stage index the biggest leak is looked for from (the step *into* that stage), and its label. Omit both and no leak is marked — a funnel with
   * a single step after the top has nothing to compare it with (see `funnelSteps`).
   */
  leakFrom?: number | undefined;
  leakLabel?: string | undefined;
}) {
  const reported = stages.map((s) => s.value).filter((v): v is number => v !== null);
  const top = reported.length > 0 ? Math.max(...reported) : 1;
  const steps = funnelSteps(
    stages.map((stage) => stage.value),
    leakFrom !== undefined && leakLabel !== undefined ? leakFrom : null,
  );

  return (
    <ol aria-label={label} className={cn('flex flex-col gap-2.5', className)}>
      {stages.map((stage, index) => {
        const step = steps[index];
        const rate = step?.rate ?? null;
        const isLeak = step?.isLeak === true;

        return (
          <li key={stage.key} className="flex flex-col gap-1">
            <div className="flex items-center gap-3">
              <span className="text-content-secondary w-24 shrink-0 truncate text-sm font-medium">
                {stage.label}
              </span>
              {stage.value === null ? (
                <span className="border-line text-content-tertiary flex h-9 flex-1 items-center rounded-xl border border-dashed px-3 text-xs">
                  {notTracked}
                </span>
              ) : (
                (() => {
                  const width = Math.max(8, (stage.value / top) * 100);
                  /*
                   * A figure written in white must sit on the bar. On a narrow bar it spills onto the pale track and
                   * becomes unreadable, so below 45% it is written beside the bar in ordinary ink instead.
                   */
                  const inside = width >= 45;
                  return (
                    <span
                      aria-hidden
                      className={cn(
                        'bg-surface-sunken flex h-9 flex-1 items-center overflow-hidden rounded-xl',
                        isLeak && 'ring-2 ring-[var(--ds-warning-600)]',
                      )}
                    >
                      <span
                        className="flex h-full shrink-0 items-center rounded-xl px-3 text-sm font-semibold text-white"
                        style={{
                          width: `${String(width)}%`,
                          backgroundColor: `color-mix(in oklab, var(--ds-accent-strong) ${String(100 - index * 12)}%, var(--ds-brand-900))`,
                        }}
                      >
                        {inside ? <span className="ds-numeric">{stage.display}</span> : null}
                      </span>
                      {inside ? null : (
                        <span className="ds-numeric text-content ms-2 truncate text-sm font-semibold">
                          {stage.display}
                        </span>
                      )}
                    </span>
                  );
                })()
              )}
              <span className="text-content-tertiary ds-numeric w-20 shrink-0 text-end text-xs">
                {rate === null ? null : (
                  <>
                    <span className="text-content-secondary font-medium">{rate}%</span>
                    <span className="ms-1.5">−{100 - rate}%</span>
                  </>
                )}
              </span>
              <span className="sr-only">{stage.value === null ? notTracked : stage.display}</span>
            </div>
            {stage.change === undefined && !isLeak ? null : (
              /* Under the bar and level with it, past the label: the bar keeps its whole width on a phone. */
              <div className="flex flex-wrap items-center gap-2 ps-[6.75rem] text-xs">
                {stage.change === undefined ? null : (
                  <span
                    className={cn(
                      'ds-numeric font-medium',
                      stage.change.good === true && 'text-success',
                      stage.change.good === false && 'text-warning',
                      stage.change.good === null && 'text-content-secondary',
                    )}
                  >
                    <bdi dir="ltr">{stage.change.text}</bdi>
                  </span>
                )}
                {isLeak ? (
                  <span className="bg-surface-sunken text-warning rounded-full px-2 py-0.5 font-medium">
                    {leakLabel}
                  </span>
                ) : null}
              </div>
            )}
          </li>
        );
      })}
    </ol>
  );
}
