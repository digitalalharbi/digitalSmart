import { useId, useState, type KeyboardEvent, type PointerEvent } from 'react';
import { cn } from '@/shared/lib/cn';
import { CHART, TOOLTIP_CLASS } from './theme';

export type TrendSeries = {
  key: string;
  label: string;
  /** One value per label. Null is a day nobody reported: a gap, never zero. */
  values: Array<number | null>;
  colour: string;
  /** Drawn thin and dashed behind the others: the previous period. */
  comparison?: boolean;
};

const W = 640;
const H = 220;
const PAD = { top: 10, bottom: 4, left: 4, right: 4 };
const TICKS = 3;

/**
 * A smooth line through the points that never leaves their range.
 *
 * Monotone cubic interpolation (Fritsch–Carlson): between two readings the curve only ever runs between them, so it
 * cannot overshoot a peak that is not in the data — the reason a spline is dishonest and this is not. A missing day
 * ends one run and starts the next; nothing is drawn across a gap.
 */
function monotone(points: Array<[number, number]>): string {
  const n = points.length;

  if (n === 0) {
    return '';
  }

  if (n === 1) {
    const [x, y] = points[0] as [number, number];

    return `M${x.toFixed(1)},${y.toFixed(1)}`;
  }

  const dx: number[] = [];
  const slope: number[] = [];

  for (let i = 0; i < n - 1; i += 1) {
    const [x0, y0] = points[i] as [number, number];
    const [x1, y1] = points[i + 1] as [number, number];
    dx.push(x1 - x0);
    slope.push((y1 - y0) / (x1 - x0 || 1));
  }

  const tangent: number[] = [slope[0] ?? 0];

  for (let i = 1; i < n - 1; i += 1) {
    const a = slope[i - 1] ?? 0;
    const b = slope[i] ?? 0;
    tangent.push(a * b <= 0 ? 0 : (2 * a * b) / (a + b));
  }

  tangent.push(slope[n - 2] ?? 0);

  let d = `M${(points[0] as [number, number])[0].toFixed(1)},${(points[0] as [number, number])[1].toFixed(1)}`;

  for (let i = 0; i < n - 1; i += 1) {
    const [x0, y0] = points[i] as [number, number];
    const [x1, y1] = points[i + 1] as [number, number];
    const w = (dx[i] ?? 0) / 3;
    d += ` C${(x0 + w).toFixed(1)},${(y0 + w * (tangent[i] ?? 0)).toFixed(1)} ${(x1 - w).toFixed(1)},${(y1 - w * (tangent[i + 1] ?? 0)).toFixed(1)} ${x1.toFixed(1)},${y1.toFixed(1)}`;
  }

  return d;
}

/**
 * The trend line: one or two series over time, with a floating tooltip that follows the pointer *and* the arrow keys.
 *
 * Drawn as SVG on purpose — a handful of shapes, no canvas that a screen reader cannot see. The chart is a labelled
 * group whose summary says what it shows; exact values are reachable by keyboard (focus it, then Left and Right).
 *
 * Time always runs left to right, in Arabic too. A timeline that reversed with the page would put "last week" on the
 * wrong side of "this week" for anybody who reads a chart from a screenshot; the labels and the tooltip are what
 * localise.
 */
export function TrendChart({
  labels,
  series,
  format,
  tickFormat,
  label,
  height = 220,
  className,
  area = true,
}: {
  labels: string[];
  series: TrendSeries[];
  /** The full figure, for the tooltip. */
  format: (value: number) => string;
  /** The short figure, for the axis. Defaults to `format`. */
  tickFormat?: (value: number) => string;
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
  const rawMax = Math.max(...all);
  const max = rawMax === min ? min + 1 : rawMax;
  const span = max - min;
  const step = (W - PAD.left - PAD.right) / (labels.length - 1);
  const x = (index: number) => PAD.left + index * step;
  const y = (value: number) => PAD.top + (1 - (value - min) / span) * (H - PAD.top - PAD.bottom);
  const short = tickFormat ?? format;

  const drawn = series.map((s) => {
    const runs: Array<Array<[number, number]>> = [];
    let run: Array<[number, number]> = [];

    s.values.forEach((value, index) => {
      if (value === null) {
        if (run.length > 0) {
          runs.push(run);
          run = [];
        }

        return;
      }

      run.push([x(index), y(value)]);
    });

    if (run.length > 0) {
      runs.push(run);
    }

    return { s, runs };
  });

  const move = (event: PointerEvent<HTMLDivElement>) => {
    const box = event.currentTarget.getBoundingClientRect();
    const fraction = (event.clientX - box.left) / box.width;

    setActive(Math.min(labels.length - 1, Math.max(0, Math.round(fraction * (labels.length - 1)))));
  };

  const key = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      event.preventDefault();
      const forward = event.key === 'ArrowRight';

      setActive((current) => {
        const from = current ?? (forward ? -1 : labels.length);

        return Math.min(labels.length - 1, Math.max(0, from + (forward ? 1 : -1)));
      });
    }

    if (event.key === 'Escape') {
      setActive(null);
    }
  };

  const ticks = Array.from({ length: TICKS + 1 }, (_, i) => ({
    fraction: i / TICKS,
    value: min + span * (1 - i / TICKS),
  }));
  const plotY = (fraction: number) => PAD.top + fraction * (H - PAD.top - PAD.bottom);

  return (
    <div dir="ltr" className={cn('flex flex-col gap-3', className)}>
      <div className="flex gap-2" style={{ height }}>
        {/* The value axis: three quiet labels, no axis line. */}
        <div
          aria-hidden
          className="text-content-tertiary relative w-11 shrink-0 text-end"
          style={{ fontSize: CHART.tickSize }}
        >
          {ticks.map((tick) => (
            <span
              key={tick.fraction}
              className="ds-numeric absolute end-0 -translate-y-1/2"
              style={{ top: `${String((plotY(tick.fraction) / H) * 100)}%` }}
            >
              {short(tick.value)}
            </span>
          ))}
        </div>

        <div
          className="relative min-w-0 flex-1 outline-none focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)]"
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
            className="h-full w-full overflow-visible"
            aria-hidden
          >
            <defs>
              {drawn.map(({ s }) => (
                <linearGradient key={s.key} id={`${id}-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={s.colour} stopOpacity={CHART.areaTop} />
                  <stop offset="100%" stopColor={s.colour} stopOpacity="0" />
                </linearGradient>
              ))}
            </defs>
            {/* Horizontal rules only, dashed and faint. */}
            {ticks.map((tick) => (
              <line
                key={tick.fraction}
                x1={PAD.left}
                x2={W - PAD.right}
                y1={plotY(tick.fraction)}
                y2={plotY(tick.fraction)}
                stroke="var(--ds-chart-grid)"
                strokeDasharray={CHART.gridDash}
                vectorEffect="non-scaling-stroke"
              />
            ))}
            {drawn.map(({ s, runs }, seriesIndex) =>
              runs.map((points, runIndex) => {
                const d = monotone(points);
                const first = points[0] as [number, number];
                const last = points[points.length - 1] as [number, number];

                return (
                  <g key={`${s.key}-${String(runIndex)}`}>
                    {area && seriesIndex === 0 && s.comparison !== true && points.length > 1 ? (
                      <path
                        d={`${d} L${last[0].toFixed(1)},${String(H - PAD.bottom)} L${first[0].toFixed(1)},${String(H - PAD.bottom)} Z`}
                        fill={`url(#${id}-${s.key})`}
                      />
                    ) : null}
                    <path
                      d={d}
                      fill="none"
                      stroke={s.colour}
                      strokeWidth={s.comparison === true ? 1.5 : CHART.lineWidth}
                      strokeDasharray={s.comparison === true ? '4 4' : undefined}
                      strokeOpacity={s.comparison === true ? 0.7 : 1}
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      vectorEffect="non-scaling-stroke"
                    />
                  </g>
                );
              }),
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
                    className="border-surface pointer-events-none absolute -translate-x-1/2 -translate-y-1/2 rounded-full border-2 shadow-xs"
                    style={{
                      width: CHART.activeDot * 2 + 4,
                      height: CHART.activeDot * 2 + 4,
                      left: `${String((x(active) / W) * 100)}%`,
                      top: `${String((y(value) / H) * 100)}%`,
                      backgroundColor: s.colour,
                    }}
                  />
                );
              })}

          {active === null ? null : (
            <div
              role="status"
              dir="auto"
              className={cn(TOOLTIP_CLASS, 'absolute top-1 -translate-x-1/2')}
              style={{ left: `${String(Math.min(86, Math.max(14, (x(active) / W) * 100)))}%` }}
            >
              <p className="text-content-secondary mb-1 font-semibold">{labels[active]}</p>
              {series.map((s) => (
                <p key={s.key} className="flex items-center justify-between gap-4">
                  <span className="text-content-secondary flex items-center gap-1.5">
                    <span
                      aria-hidden
                      className="size-2 rounded-full"
                      style={{ backgroundColor: s.colour }}
                    />
                    {s.label}
                  </span>
                  <span className="ds-numeric text-content font-semibold">
                    {s.values[active] === null || s.values[active] === undefined
                      ? '—'
                      : format(s.values[active])}
                  </span>
                </p>
              ))}
            </div>
          )}
        </div>
      </div>

      <div
        className="text-content-tertiary ms-[3.25rem] flex justify-between"
        style={{ fontSize: CHART.tickSize }}
        aria-hidden
      >
        <span>{labels[0]}</span>
        <span>{labels[Math.floor(labels.length / 2)]}</span>
        <span>{labels[labels.length - 1]}</span>
      </div>

      {series.length > 1 ? (
        <ul className="ms-[3.25rem] flex flex-wrap gap-4" style={{ fontSize: CHART.textSize }}>
          {series.map((s) => (
            <li key={s.key} className="text-content-secondary flex items-center gap-2">
              <span
                aria-hidden
                className="size-2.5 rounded-full"
                style={{ backgroundColor: s.colour, opacity: s.comparison === true ? 0.6 : 1 }}
              />
              {s.label}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
