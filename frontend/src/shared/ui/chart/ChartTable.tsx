import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

export type ChartTableRow = {
  key: string;
  /** The row's name, in the first column. */
  label: ReactNode;
  /** One cell per column after the first. */
  cells: ReactNode[];
};

type ChartTableProps = {
  /** Column headings, the first being the row name's column. */
  columns: string[];
  rows: ChartTableRow[];
  /** Describes the table for a screen reader, and for anyone who turns it on. */
  caption: string;
  className?: string;
};

/**
 * The same figures as a table.
 *
 * Every chart in this product has one, and it is not a fallback. A chart encodes with
 * position and colour, and some readers get neither: a colour-blind reader on a
 * five-series chart, somebody on a screen reader, somebody printing in grey. A tooltip
 * does not help any of them, because a tooltip needs a pointer and a canvas tells a
 * screen reader nothing at all.
 *
 * It is also the relief the palette depends on. Three of the lighter series sit close to
 * the contrast floor on a white card; the published rule allows that **only** where the
 * values are readable another way, and this is that way.
 */
export function ChartTable({ columns, rows, caption, className }: ChartTableProps) {
  return (
    <div className={cn('overflow-x-auto', className)}>
      <table className="w-full border-collapse text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-line-subtle border-b">
            {columns.map((column, index) => (
              <th
                key={column}
                scope="col"
                className={cn(
                  'text-content-tertiary px-2 py-2 text-xs font-medium tracking-wide uppercase',
                  // The first column names the row; the rest are numbers and align to the end.
                  index === 0 ? 'text-start' : 'text-end',
                )}
              >
                {column}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className="border-line-subtle border-b last:border-0">
              <th scope="row" className="text-content px-2 py-2 text-start font-normal">
                {row.label}
              </th>
              {row.cells.map((cell, index) => (
                <td
                  // A cell has no identity of its own; its column position is the key.
                  key={columns[index + 1] ?? String(index)}
                  // Tabular figures so a column aligns on the decimal, which is what makes
                  // a table of numbers scannable rather than merely dense.
                  className="text-content-secondary px-2 py-2 text-end tabular-nums"
                >
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
