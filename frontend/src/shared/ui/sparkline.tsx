import { cn } from '@/shared/lib/cn';

/**
 * A line with no axes: the shape of the last two weeks, nothing more.
 *
 * A `null` is a day nobody reported, drawn as a gap. It is never bridged and never zero, for the same
 * reason the fact table keeps them apart: a line that dips to nothing on a day the provider simply did
 * not report reads as a collapse in performance that never happened.
 *
 * Decorative by default — the figure beside it carries the number — and named when `label` is given.
 */
export function Sparkline({
  values,
  label,
  className,
  stretch = false,
}: {
  values: Array<number | null>;
  label?: string | undefined;
  className?: string | undefined;
  /** Fill the box it is given instead of keeping its own proportions — for a hero line rather than a card's. */
  stretch?: boolean;
}) {
  const width = 80;
  const height = 24;
  const known = values.filter((v): v is number => v !== null);

  if (known.length < 2) {
    return (
      <span aria-hidden className={cn('text-content-tertiary text-xs', className)}>
        —
      </span>
    );
  }

  const min = Math.min(...known);
  const max = Math.max(...known);
  const span = max - min || 1;
  const step = width / Math.max(values.length - 1, 1);

  const segments: string[] = [];
  let current = '';

  values.forEach((value, index) => {
    if (value === null) {
      if (current !== '') {
        segments.push(current);
        current = '';
      }

      return;
    }

    const x = (index * step).toFixed(1);
    const y = (height - 2 - ((value - min) / span) * (height - 4)).toFixed(1);
    current += `${current === '' ? 'M' : 'L'}${x},${y}`;
  });

  if (current !== '') {
    segments.push(current);
  }

  return (
    <svg
      viewBox={`0 0 ${String(width)} ${String(height)}`}
      preserveAspectRatio={stretch ? 'none' : undefined}
      className={cn('h-6 w-20 shrink-0', className)}
      role={label === undefined ? undefined : 'img'}
      aria-label={label}
      aria-hidden={label === undefined ? true : undefined}
    >
      {segments.map((d) => (
        <path
          key={d}
          d={d}
          fill="none"
          stroke="var(--ds-accent)"
          strokeWidth={1.5}
          strokeLinecap="round"
          strokeLinejoin="round"
          vectorEffect={stretch ? 'non-scaling-stroke' : undefined}
        />
      ))}
    </svg>
  );
}
