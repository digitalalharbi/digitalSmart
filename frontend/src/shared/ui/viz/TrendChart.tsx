import { useId, useState, type KeyboardEvent, type PointerEvent } from 'react';
import { cn } from '@/shared/lib/cn';

export type TrendSeries = {
  key: string;
  label: string;
  /** One value per label. Null is a day nobody reported: a gap, never zero. */
  values: Array<number | null>;
  colour: string;
};

const W = 640;
const H = 220;
const PAD = { top: 12, bottom: 6, left: 4, right: 4 };

/**
 * The hero line: one or two series over time, with a tooltip that follows the pointer *and* the arrow keys.
 *
 * Drawn as SVG on purpose — the figures are few, the shapes are simple, and a canvas would be invisible to a
 * screen reader. The chart is a labelled image whose summary says what it shows; the exact values are available to
 * anyone by keyboard (focus it, then Left and Right) and to anyone by a "view data" table the page offers beside it.
 * Missing days break the line.
 */
export function TrendChart({
  labels,
  series,
  format,
  label,
  height = 220,
  className,
  area = true,
}: {
  labels: string[];
  series: TrendSeries[];
  format: (value: number) => string;
  label: string;
  height?: number;
  className?: string | undefined;
  area?: boolean;
}) {
  const id = useId();
  const [active, setActive] = useState<number | null>(null);

  const all = series.flatMap((s) => s.values).filter((v): v is number => v !== null);

  if (all.length < 2 || labels.length < 2) {
    return null;
  }

  const min = Math.min(0, ...all);
  const max = Math.max(...all);
  const span = max - min || 1;
  const step = (W - PAD.left - PAD.right) / (labels.length - 1);
  const x = (index: number) => PAD.left + index * step;
  const y = (value: number) => PAD.top + (1 - (value - min) / span) * (H - PAD.top - PAD.bottom);

  const paths = series.map((s) => {
    const segments: string[] = [];
    let run: Array<[number, number]> = [];

    s.values.forEach((value, index) => {
      if (value === null) {
        if (run.length > 0) {
          segments.push(toPath(run));
          run = [];
        }

        return;
      }

      run.push([x(index), y(value)]);
    });

    if (run.length > 0) {
      segments.push(toPath(run));
    }

    return { s, line: segments.join(' '), runs: segments };
  });

  const move = (event: PointerEvent<HTMLDivElement>) => {
    const box = event.currentTarget.getBoundingClientRect();
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    const ratio = (event.clientX - box.left) / box.width;
    const fraction = rtl ? 1 - ratio : ratio;

    setActive(Math.min(labels.length - 1, Math.max(0, Math.round(fraction * (labels.length - 1)))));
  };

  const key = (event: KeyboardEvent<HTMLDivElement>) => {
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
    const back = rtl ? 'ArrowRight' : 'ArrowLeft';

    if (event.key === forward || event.key === back) {
      event.preventDefault();
      setActive((current) => {
        const from = current ?? (event.key === forward ? -1 : labels.length);

        return Math.min(labels.length - 1, Math.max(0, from + (event.key === forward ? 1 : -1)));
      });
    }

    if (event.key === 'Escape') {
      setActive(null);
    }
  };

  const gridLines = [0, 0.5, 1].map((fraction) => ({
    fraction,
    value: min + span * (1 - fraction),
  }));

  return (
    <div className={cn('flex flex-col gap-3', className)}>
      <div
        className="relative"
        style={{ height }}
        onPointerMove={move}
        onPointerLeave={() => setActive(null)}
        onKeyDown={key}
        onBlur={() => setActive(null)}
        tabIndex={0}
        role="group"
        aria-label={label}
      >
        <svg
          viewBox={`0 0 ${String(W)} ${String(H)}`}
          preserveAspectRatio="none"
          className="h-full w-full overflow-visible rtl:-scale-x-100"
          aria-hidden
        >
          <defs>
            {paths.map(({ s }) => (
              <linearGradient key={s.key} id={`${id}-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor={s.colour} stopOpacity="0.22" />
                <stop offset="100%" stopColor={s.colour} stopOpacity="0" />
              </linearGradient>
            ))}
          </defs>
          {gridLines.map((g) => (
            <line
              key={g.fraction}
              x1={PAD.left}
              x2={W - PAD.right}
              y1={PAD.top + g.fraction * (H - PAD.top - PAD.bottom)}
              y2={PAD.top + g.fraction * (H - PAD.top - PAD.bottom)}
              stroke="var(--ds-border-subtle)"
              strokeDasharray="3 5"
              vectorEffect="non-scaling-stroke"
            />
          ))}
          {paths.map(({ s, runs }, seriesIndex) =>
            runs.map((run, runIndex) => (
              <g key={`${s.key}-${String(runIndex)}`}>
                {area && seriesIndex === 0 ? (
                  <path
                    d={`${run} L${lastX(run)},${String(H - PAD.bottom)} L${firstX(run)},${String(H - PAD.bottom)} Z`}
                    fill={`url(#${id}-${s.key})`}
                  />
                ) : null}
                <path
                  d={run}
                  fill="none"
                  stroke={s.colour}
                  strokeWidth={2.5}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  vectorEffect="non-scaling-stroke"
                />
              </g>
            )),
          )}
          {active === null ? null : (
            <line
              x1={x(active)}
              x2={x(active)}
              y1={PAD.top}
              y2={H - PAD.bottom}
              stroke="var(--ds-border-strong)"
              vectorEffect="non-scaling-stroke"
            />
          )}
        </svg>

        {active === null
          ? null
          : series.map((s) => {
              const value = s.values[active];

              return value === null || value === undefined ? null : (
                <span
                  key={s.key}
                  aria-hidden
                  className="pointer-events-none absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-xs rtl:translate-x-1/2"
                  style={{
                    insetInlineStart: `${String((x(active) / W) * 100)}%`,
                    top: `${String((y(value) / H) * 100)}%`,
                    backgroundColor: s.colour,
                  }}
                />
              );
            })}

        {active === null ? null : (
          <div
            role="status"
            className="bg-action text-action-contrast pointer-events-none absolute top-1 z-10 min-w-32 -translate-x-1/2 rounded-2xl px-3 py-2 text-xs shadow-md rtl:translate-x-1/2"
            style={{
              insetInlineStart: `${String(Math.min(88, Math.max(12, (x(active) / W) * 100)))}%`,
            }}
          >
            <p className="mb-1 opacity-70">{labels[active]}</p>
            {series.map((s) => (
              <p key={s.key} className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-1.5">
                  <span
                    aria-hidden
                    className="size-2 rounded-full"
                    style={{ backgroundColor: s.colour }}
                  />
                  {s.label}
                </span>
                <span className="ds-numeric font-medium">
                  {s.values[active] === null || s.values[active] === undefined
                    ? '—'
                    : format(s.values[active])}
                </span>
              </p>
            ))}
          </div>
        )}
      </div>

      <div className="text-content-tertiary flex justify-between text-xs" aria-hidden>
        <span>{labels[0]}</span>
        <span>{labels[Math.floor(labels.length / 2)]}</span>
        <span>{labels[labels.length - 1]}</span>
      </div>

      {series.length > 1 ? (
        <ul className="flex flex-wrap gap-4 text-xs">
          {series.map((s) => (
            <li key={s.key} className="text-content-secondary flex items-center gap-2">
              <span
                aria-hidden
                className="h-1 w-4 rounded-full"
                style={{ backgroundColor: s.colour }}
              />
              {s.label}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

function toPath(points: Array<[number, number]>): string {
  return points
    .map(([px, py], index) => `${index === 0 ? 'M' : 'L'}${px.toFixed(1)},${py.toFixed(1)}`)
    .join(' ');
}

function firstX(run: string): string {
  return /M([\d.]+),/.exec(run)?.[1] ?? '0';
}

function lastX(run: string): string {
  const all = [...run.matchAll(/[ML]([\d.]+),/g)];

  return all[all.length - 1]?.[1] ?? '0';
}
