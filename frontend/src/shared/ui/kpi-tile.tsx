import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';

/**
 * One headline figure: a label, the number, and at most one short line under it.
 *
 * `highlight` is the orange tile the reference leads with — used once per strip, for the figure the page is about.
 * It sits on the AA-safe orange so its small label still reads. With `to`, the whole tile is the link to the page
 * that holds the detail behind the number.
 */
export function KpiTile({
  label,
  value,
  sub,
  highlight = false,
  to,
  className,
}: {
  label: string;
  value: string;
  sub?: string | undefined;
  highlight?: boolean;
  to?: string | undefined;
  className?: string | undefined;
}) {
  const body = (
    <>
      <span
        className={cn(
          'text-xs font-medium',
          highlight ? 'text-white' : 'text-content-secondary',
        )}
      >
        {label}
      </span>
      <span className="ds-numeric text-2xl leading-tight font-semibold sm:text-[1.75rem]">
        {value}
      </span>
      {sub === undefined ? null : (
        <span className={cn('text-xs', highlight ? 'text-white' : 'text-content-tertiary')}>
          {sub}
        </span>
      )}
    </>
  );

  const shape = cn(
    'flex min-w-0 flex-col gap-1.5 rounded-3xl p-5 shadow-xs',
    highlight ? 'bg-accent-strong text-white' : 'bg-surface text-content',
    className,
  );

  return to === undefined ? (
    <div className={shape}>{body}</div>
  ) : (
    <Link
      to={to}
      className={cn(
        shape,
        'ease-standard transition-shadow duration-[var(--ds-duration-fast)] hover:shadow-md focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)] focus-visible:outline-none',
      )}
    >
      {body}
    </Link>
  );
}
