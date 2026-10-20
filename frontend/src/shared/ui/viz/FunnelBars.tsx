import { cn } from '@/shared/lib/cn';

export type FunnelStage = {
  key: string;
  label: string;
  value: number | null;
  display: string;
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
}: {
  stages: FunnelStage[];
  className?: string | undefined;
  label: string;
  notTracked: string;
}) {
  const reported = stages.map((s) => s.value).filter((v): v is number => v !== null);
  const top = reported.length > 0 ? Math.max(...reported) : 1;

  return (
    <ol aria-label={label} className={cn('flex flex-col gap-2.5', className)}>
      {stages.map((stage, index) => {
        const previous = index === 0 ? null : (stages[index - 1]?.value ?? null);
        const rate =
          stage.value !== null && previous !== null && previous > 0 && stage.value <= previous
            ? Math.round((stage.value / previous) * 100)
            : null;

        return (
          <li key={stage.key} className="flex items-center gap-3">
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
                    className="bg-surface-sunken flex h-9 flex-1 items-center overflow-hidden rounded-xl"
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
                  {previous !== null && stage.value !== null ? (
                    <span className="ms-1.5">−{100 - rate}%</span>
                  ) : null}
                </>
              )}
            </span>
            <span className="sr-only">{stage.value === null ? notTracked : stage.display}</span>
          </li>
        );
      })}
    </ol>
  );
}
