import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';

export type RankingRow = {
  key: string;
  label: string;
  /** The number the bar is drawn from. Null is "nothing reported" and draws no bar. */
  value: number | null;
  /** The figure as written, with its currency. */
  display: string;
  /** Where the row leads. */
  to?: string | undefined;
  /** A small second line: a project count, a state. */
  hint?: string | undefined;
  colour?: string | undefined;
};

/**
 * A list ranked by a bar: who is first, and by how much, at a glance.
 *
 * The bar is a share of the largest row, so it shows relative size rather than pretending to be a percentage of
 * something. The figure is always written beside it — a length alone is a claim nobody can check.
 */
export function RankingBars({
  rows,
  className,
  label,
}: {
  rows: RankingRow[];
  className?: string | undefined;
  label: string;
}) {
  const largest = Math.max(1, ...rows.map((row) => row.value ?? 0));

  return (
    <ol aria-label={label} className={cn('flex flex-col gap-3', className)}>
      {rows.map((row, index) => {
        const name = (
          <span className="flex min-w-0 items-baseline gap-2">
            <span className="text-content-tertiary ds-numeric w-4 shrink-0 text-xs">
              {index + 1}
            </span>
            <span className="text-content truncate text-sm font-medium">{row.label}</span>
          </span>
        );

        return (
          <li key={row.key} className="flex flex-col gap-1.5">
            <div className="flex items-baseline justify-between gap-3">
              {row.to === undefined ? (
                name
              ) : (
                <Link to={row.to} className="min-w-0 hover:underline">
                  {name}
                </Link>
              )}
              <span className="ds-numeric text-content shrink-0 text-sm">{row.display}</span>
            </div>
            <span
              aria-hidden
              className="bg-surface-sunken block h-2 w-full overflow-hidden rounded-full"
            >
              {row.value === null ? null : (
                <span
                  className="block h-full rounded-md"
                  style={{
                    width: `${String(Math.max(2, (row.value / largest) * 100))}%`,
                    backgroundColor: row.colour ?? 'var(--ds-accent)',
                  }}
                />
              )}
            </span>
            {row.hint === undefined ? null : (
              <span className="text-content-tertiary ps-6 text-xs">{row.hint}</span>
            )}
          </li>
        );
      })}
    </ol>
  );
}
