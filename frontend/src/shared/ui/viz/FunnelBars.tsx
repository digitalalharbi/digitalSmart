import { cn } from '@/shared/lib/cn';

export type FunnelStage = {
  key: string;
  label: string;
  value: number | null;
  display: string;
};

/**
 * A funnel: each stage a bar, narrowing, with the step-to-step rate between them.
 *
 * A stage nobody measured is drawn as "not tracked" rather than as zero — a funnel that silently reports zero
 * qualified leads when qualification simply is not recorded would read as a collapse that never happened.
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
  const first = stages.find((stage) => stage.value !== null)?.value ?? 0;
  const top = Math.max(first, 1);

  return (
    <ol aria-label={label} className={cn('flex flex-col gap-2.5', className)}>
      {stages.map((stage, index) => {
        const previous = index === 0 ? null : (stages[index - 1]?.value ?? null);
        const rate =
          stage.value !== null && previous !== null && previous > 0
            ? Math.round((stage.value / previous) * 100)
            : null;

        return (
          <li key={stage.key} className="flex flex-col gap-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
              <span className="text-content-secondary">{stage.label}</span>
              <span className="flex items-baseline gap-2">
                {rate === null ? null : (
                  <span className="text-content-tertiary ds-numeric text-xs">{rate}%</span>
                )}
                <span className="ds-numeric text-content font-medium">
                  {stage.value === null ? notTracked : stage.display}
                </span>
              </span>
            </div>
            <span
              aria-hidden
              className="bg-surface-sunken block h-7 w-full overflow-hidden rounded-xl"
            >
              {stage.value === null ? null : (
                <span
                  className="block h-full rounded-xl"
                  style={{
                    width: `${String(Math.max(3, (stage.value / top) * 100))}%`,
                    backgroundColor: `color-mix(in oklch, var(--ds-accent) ${String(100 - index * 20)}%, var(--ds-surface-sunken))`,
                  }}
                />
              )}
            </span>
          </li>
        );
      })}
    </ol>
  );
}
