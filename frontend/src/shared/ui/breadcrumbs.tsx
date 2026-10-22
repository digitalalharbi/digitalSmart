import { ChevronRight } from 'lucide-react';
import { Fragment } from 'react';
import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';

export type Crumb = { label: string; to?: string | undefined };

/**
 * Where this page sits, as a trail a reader can walk back along: Campaigns / a campaign / one of its ad groups.
 *
 * A real `<nav>` with an ordered list, the last item the current page. The separator is a chevron flipped in
 * right-to-left — a chevron is a direction, and a right-pointing one in an Arabic trail points back the way the
 * reader came from. Names are `dir="auto"` because a campaign's name is whatever its advertiser typed, in any script.
 */
export function Breadcrumbs({ items, label }: { items: Crumb[]; label: string }) {
  return (
    <nav aria-label={label}>
      <ol className="text-content-secondary flex flex-wrap items-center gap-1.5 text-sm">
        {items.map((item, index) => {
          const isLast = index === items.length - 1;

          return (
            <Fragment key={`${index}-${item.label}`}>
              <li className="flex min-w-0 items-center gap-1.5">
                {index === 0 ? null : (
                  <ChevronRight
                    aria-hidden
                    className="text-content-tertiary size-3.5 shrink-0 rtl:rotate-180"
                  />
                )}
                {item.to === undefined || isLast ? (
                  <span
                    dir="auto"
                    aria-current={isLast ? 'page' : undefined}
                    className={cn('max-w-[16rem] truncate', isLast && 'text-content font-medium')}
                  >
                    {item.label}
                  </span>
                ) : (
                  <Link
                    to={item.to}
                    dir="auto"
                    className="hover:text-content max-w-[16rem] truncate hover:underline"
                  >
                    {item.label}
                  </Link>
                )}
              </li>
            </Fragment>
          );
        })}
      </ol>
    </nav>
  );
}
