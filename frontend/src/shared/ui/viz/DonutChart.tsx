import { cn } from '@/shared/lib/cn';

export type DonutSegment = {
  key: string;
  label: string;
  value: number;
  /** A CSS colour, normally a design token: `var(--ds-success)`. */
  colour: string;
};

/**
 * Part of a whole, as a ring with the one number that matters in the middle.
 *
 * Used where the answer is a share — campaign health, platform mix, budget health — and never for more than six
 * slices: past that a ring is a colour wheel nobody can read. Every slice is also written out in the legend with
 * its count, so colour is never the only carrier of meaning and the ring is decoration over a list.
 */
export function DonutChart({
  segments,
  centre,
  caption,
  size = 168,
  thickness = 18,
  label,
  className,
  legend = true,
  format = (n) => n.toLocaleString(),
}: {
  segments: DonutSegment[];
  /** The headline in the hole: a total, a percentage, a state. */
  centre?: { value: string; caption?: string } | undefined;
  caption?: string | undefined;
  size?: number;
  thickness?: number;
  /** What the ring says, for assistive technology. */
  label: string;
  className?: string | undefined;
  legend?: boolean;
  format?: (value: number) => string;
}) {
  const shown = segments.filter((segment) => segment.value > 0);
  const total = shown.reduce((sum, segment) => sum + segment.value, 0);
  const radius = (size - thickness) / 2;
  const circumference = 2 * Math.PI * radius;
  const gap = shown.length > 1 ? 3 : 0;

  let offset = 0;

  return (
    <div className={cn('flex flex-col items-center gap-4 sm:flex-row sm:items-center', className)}>
      <div className="relative shrink-0" style={{ width: size, height: size }}>
        <svg
          viewBox={`0 0 ${String(size)} ${String(size)}`}
          width={size}
          height={size}
          role="img"
          aria-label={label}
          className="-rotate-90 rtl:-scale-x-100 rtl:rotate-90"
        >
          <circle
            cx={size / 2}
            cy={size / 2}
            r={radius}
            fill="none"
            stroke="var(--ds-surface-sunken)"
            strokeWidth={thickness}
          />
          {total === 0
            ? null
            : shown.map((segment) => {
                const length = (segment.value / total) * circumference;
                const dash = Math.max(length - gap, 0.5);
                const start = offset;
                offset += length;

                return (
                  <circle
                    key={segment.key}
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={segment.colour}
                    strokeWidth={thickness}
                    strokeLinecap="round"
                    strokeDasharray={`${String(dash)} ${String(circumference - dash)}`}
                    strokeDashoffset={-start}
                  />
                );
              })}
        </svg>
        {centre === undefined ? null : (
          <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
            <span className="ds-numeric text-content text-2xl leading-none font-semibold">
              {centre.value}
            </span>
            {centre.caption === undefined ? null : (
              <span className="text-content-tertiary mt-1 max-w-[7rem] text-xs">
                {centre.caption}
              </span>
            )}
          </div>
        )}
      </div>

      {legend ? (
        <ul className="flex min-w-0 flex-1 flex-col gap-1.5 self-stretch text-sm">
          {segments.map((segment) => (
            <li key={segment.key} className="flex items-center justify-between gap-3">
              <span className="text-content-secondary flex min-w-0 items-center gap-2">
                <span
                  aria-hidden
                  className="size-2.5 shrink-0 rounded-full"
                  style={{ backgroundColor: segment.colour }}
                />
                <span className="truncate">{segment.label}</span>
              </span>
              <span className="ds-numeric text-content shrink-0 font-medium">
                {format(segment.value)}
              </span>
            </li>
          ))}
        </ul>
      ) : null}

      {caption === undefined ? null : <p className="sr-only">{caption}</p>}
    </div>
  );
}
