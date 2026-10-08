import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

type ScrollRegionProps = Omit<HTMLAttributes<HTMLDivElement>, 'aria-label'> & {
  /** What is inside, named — a focusable region with no name is a tab stop nobody can identify. */
  label: string;
  children: ReactNode;
};

/**
 * A horizontally scrollable panel that a keyboard can reach.
 *
 * Wide tables and board columns scroll sideways on a narrow screen, and a region that scrolls with no way into
 * it from the keyboard hides its own content: a mouse can drag it, a trackpad can swipe it, and somebody using
 * a keyboard simply never sees the last three columns. WCAG 2.1.1, and axe reports it as serious — which is
 * where this came from, on the analytics breakdown at phone width.
 *
 * `tabIndex={0}` is the published remedy. It costs one extra tab stop on a wide screen where the panel does not
 * actually scroll, which is the trade the standard asks for; making it conditional would mean measuring the
 * overflow on every resize and getting the answer wrong during the first paint.
 *
 * `role="group"` with the label, rather than `region`, deliberately: a landmark per table would fill the
 * landmark list with furniture, while a named group tells a screen reader what it has just entered.
 */
export function ScrollRegion({ label, className, children, ...props }: ScrollRegionProps) {
  return (
    <div
      role="group"
      aria-label={label}
      tabIndex={0}
      /* The ring comes from the global `:focus-visible` rule, which matters more here than usual: this stop has
         no visible control to land on. */
      className={cn('overflow-x-auto', className)}
      {...props}
    >
      {children}
    </div>
  );
}
