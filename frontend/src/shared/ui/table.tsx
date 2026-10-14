import type {
  CSSProperties,
  HTMLAttributes,
  ReactNode,
  TdHTMLAttributes,
  ThHTMLAttributes,
} from 'react';
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
 *
 * **On a phone a row becomes a card.** A desktop table squeezed onto 375 pixels is a horizontal scroll that
 * hides most of its columns behind a gesture; here the header row is hidden below `sm`, each row becomes a
 * block, and every cell carries its own column label. It is still a real `<table>` — the row and column
 * relationships a screen reader depends on survive — it is only laid out differently.
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
      {/*
        The minimum width applies from `sm` up only. On a phone the rows are cards and a minimum width would
        reintroduce exactly the horizontal scroll the card layout exists to remove.
      */}
      <table
        className={cn('w-full text-sm max-sm:block sm:min-w-(--ds-table-min)', className)}
        style={{ '--ds-table-min': minWidth } as CSSProperties}
        {...props}
      >
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

/** Hidden on a phone, where each cell carries its own label instead. */
export function TableHead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return (
    <thead className={cn('border-line-subtle border-b max-sm:hidden', className)} {...props} />
  );
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return (
    <tbody
      className={cn('divide-line-subtle divide-y max-sm:block max-sm:divide-y-0', className)}
      {...props}
    />
  );
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
        /* The card a row becomes on a phone. */
        'max-sm:bg-surface max-sm:border-line-subtle max-sm:mb-2 max-sm:block max-sm:rounded-2xl max-sm:border max-sm:p-2 max-sm:shadow-xs',
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
  /** The column's name, shown only on a phone — where there is no header row to read it from. */
  label,
  className,
  children,
  ...props
}: Omit<TdHTMLAttributes<HTMLTableCellElement>, 'align'> & {
  align?: 'start' | 'end';
  isNumeric?: boolean;
  label?: string | undefined;
}) {
  return (
    <td
      className={cn(
        'text-content px-5 py-3.5 align-middle',
        align === 'end' ? 'text-end' : 'text-start',
        isNumeric && 'ds-numeric',
        'max-sm:flex max-sm:items-center max-sm:justify-between max-sm:gap-4 max-sm:px-3 max-sm:py-2 max-sm:text-start',
        className,
      )}
      {...props}
    >
      {label === undefined ? null : (
        <span aria-hidden="true" className="text-content-tertiary ds-overline hidden max-sm:block">
          {label}
        </span>
      )}
      {children as ReactNode}
    </td>
  );
}
