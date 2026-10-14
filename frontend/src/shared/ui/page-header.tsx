import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

type PageHeaderProps = {
  title: string;
  description?: string | undefined;
  /** The one action this page exists to perform. Anything else belongs in `actions`. */
  primaryAction?: ReactNode;
  actions?: ReactNode;
  /** Filters, scopes and period pickers — rendered on their own line so the title never competes with them. */
  filters?: ReactNode;
  className?: string | undefined;
};

/**
 * One page header for the whole product.
 *
 * Before this, every page wrote its own `<header>`: some put the client picker beside the title, some below
 * it, and the gap between title and content ranged from 12 to 32 pixels. A reader moving between surfaces
 * felt that as a different product each time, which is exactly the thing a design system exists to stop.
 *
 * The rules it encodes:
 *
 * **The title is an `h1` and the only one.** Tests and screen readers both depend on that, and a page with two
 * first-level headings has no first-level heading.
 *
 * **Actions are right-aligned and wrap underneath**, never squeezed: on a phone the title keeps its line and
 * the actions take the next one, because a truncated primary action is a button nobody can read.
 *
 * **Filters get their own row.** They are the noisiest part of an analytical page and the least important to
 * read first.
 */
export function PageHeader({
  title,
  description,
  primaryAction,
  actions,
  filters,
  className,
}: PageHeaderProps) {
  return (
    <header className={cn('flex flex-col gap-4', className)}>
      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div className="min-w-0">
          <h1 className="text-content text-2xl font-semibold tracking-tight">{title}</h1>
          {description === undefined ? null : (
            <p className="text-content-secondary mt-1.5 max-w-2xl text-sm">{description}</p>
          )}
        </div>

        {actions === undefined && primaryAction === undefined ? null : (
          <div className="flex flex-wrap items-center gap-2">
            {actions}
            {primaryAction}
          </div>
        )}
      </div>

      {filters === undefined ? null : (
        <div className="flex flex-wrap items-end gap-3">{filters}</div>
      )}
    </header>
  );
}

/**
 * A titled region of a page.
 *
 * Sections are how an analytical page gets rhythm: a heading, an optional line of context, an optional action,
 * and the content. It renders a real `section` with its heading wired by `aria-labelledby`, so the page's
 * structure is navigable rather than a stack of divs.
 */
export function PageSection({
  title,
  description,
  actions,
  children,
  className,
}: {
  title: string;
  description?: string | undefined;
  actions?: ReactNode;
  children: ReactNode;
  className?: string | undefined;
}) {
  const id = `section-${title.replace(/\s+/g, '-').toLowerCase()}`;

  return (
    <section aria-labelledby={id} className={cn('flex flex-col gap-3', className)}>
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
        <div className="min-w-0">
          <h2 id={id} className="text-content text-lg font-semibold">
            {title}
          </h2>
          {description === undefined ? null : (
            <p className="text-content-secondary mt-1 text-sm">{description}</p>
          )}
        </div>
        {actions}
      </div>
      {children}
    </section>
  );
}
