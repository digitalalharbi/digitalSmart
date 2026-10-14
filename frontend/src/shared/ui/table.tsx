import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/shared/lib/cn';
import { ScrollRegion } from '@/shared/ui/scroll-region';

/**
 * The product's table surface.
 *
 * Thirteen tables were each styling their own header row, and the differences showed: three header fills, two
 * divider weights, four padding scales. This is that decision made once.
 *
 * What it encodes:
 *
 * **No header fill and no vertical rules.** A tinted header band and a grid of lines are the two things that
 * make a table look like a spreadsheet; the header earns its weight from small-caps tertiary ink instead, and
 * the rows are separated by a hairline alone.
 *
 * **The scroll region is part of the table**, named and reachable from a keyboard — a wide table that scrolls
 * sideways with no way into it hides its last columns from anyone not using a mouse.
 *
 * **Numbers right, text start.** `align` is a prop rather than a class so a column cannot be aligned one way
 * in the header and another in the body, which is the commonest way a numeric column stops being scannable.
 */
export function DataTable({
  label,
  caption,
  minWidth = '48rem',
  className,
  children,
  ...props
}: Omit<HTMLAttributes<HTMLTableElement>, 'className'> & {
  /** Names the scroll region and captions the table for a screen reader. */
  label: string;
  /** Visible caption, where the surrounding card does not already carry the name. */
  caption?: string | undefined;
  minWidth?: string | undefined;
  className?: string | undefined;
}) {
  return (
    <ScrollRegion
      label={label}
      className="bg-surface border-line-subtle rounded-2xl border shadow-xs"
    >
      <table className={cn('w-full text-sm', className)} style={{ minWidth }} {...props}>
        <caption
          className={
            caption === undefined
              ? 'sr-only'
              : 'text-content-secondary px-5 pt-4 pb-2 text-start text-sm'
          }
        >
          {caption ?? label}
        </caption>
        {children}
      </table>
    </ScrollRegion>
  );
}

export function TableHead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={cn('border-line-subtle border-b', className)} {...props} />;
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <tbody className={cn('divide-line-subtle divide-y', className)} {...props} />;
}

export function TableRow({
  isInteractive = false,
  className,
  ...props
}: HTMLAttributes<HTMLTableRowElement> & { isInteractive?: boolean }) {
  return (
    <tr
      className={cn(
        'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
        isInteractive && 'hover:bg-surface-hover',
        className,
      )}
      {...props}
    />
  );
}

/** A column heading. `align` must match the cells below it. */
export function TableHeader({
  align = 'start',
  className,
  ...props
}: Omit<ThHTMLAttributes<HTMLTableCellElement>, 'align'> & { align?: 'start' | 'end' }) {
  return (
    <th
      scope="col"
      className={cn(
        'text-content-tertiary ds-overline px-5 py-3 font-semibold',
        align === 'end' ? 'text-end' : 'text-start',
        className,
      )}
      {...props}
    />
  );
}

export function TableCell({
  align = 'start',
  /** Numeric cells are tabular so a column aligns on the decimal. */
  isNumeric = false,
  className,
  ...props
}: Omit<TdHTMLAttributes<HTMLTableCellElement>, 'align'> & {
  align?: 'start' | 'end';
  isNumeric?: boolean;
}) {
  return (
    <td
      className={cn(
        'text-content px-5 py-3.5 align-middle',
        align === 'end' ? 'text-end' : 'text-start',
        isNumeric && 'ds-numeric',
        className,
      )}
      {...props}
    />
  );
}
