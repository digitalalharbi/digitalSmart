/**
 * A word-sized line of how a figure moved, drawn from its own daily values.
 *
 * It says nothing the figure beside it does not: no axis, no value labels, no colour for good or bad — a sparkline
 * that coloured itself would be a second opinion on whether the change is good, and that call belongs to the metric.
 * A missing day is a break in the line, never a zero: "nothing was reported" and "nothing happened" are different
 * facts, and drawing the first as the second is how a tracking gap becomes a collapse in a client's report.
 *
 * It is decoration for sighted readers; the figures themselves are in the table twin of the section it sits in, so it
 * is hidden from assistive technology rather than described badly.
 */
export function Sparkline({
  values,
  width = 88,
  height = 28,
  className,
}: {
  values: Array<number | null>;
  width?: number;
  height?: number;
  className?: string | undefined;
}) {
  const present = values.filter((value): value is number => value !== null);

  /* One point is not a movement, and a flat line of nothing would read as "no change". */
  if (present.length < 2) {
    return null;
  }

  const low = Math.min(...present);
  const high = Math.max(...present);
  const span = high - low || 1;
  const pad = 2;
  const step = (width - pad * 2) / (values.length - 1);
  const y = (value: number) => height - pad - ((value - low) / span) * (height - pad * 2);

  /* Consecutive present points form a run; a null ends it. */
  const runs: string[][] = [[]];
  values.forEach((value, index) => {
    if (value === null) {
      if ((runs[runs.length - 1] ?? []).length > 0) {
        runs.push([]);
      }
      return;
    }
    runs[runs.length - 1]?.push(`${(pad + index * step).toFixed(1)},${y(value).toFixed(1)}`);
  });

  return (
    <svg
      aria-hidden
      focusable="false"
      width={width}
      height={height}
      viewBox={`0 0 ${String(width)} ${String(height)}`}
      className={className}
    >
      {runs
        .filter((run) => run.length > 1)
        .map((run, index) => (
          <polyline
            key={index}
            points={run.join(' ')}
            fill="none"
            stroke="var(--ds-accent)"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        ))}
    </svg>
  );
}
