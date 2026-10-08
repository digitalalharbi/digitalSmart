import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

type LoadingRegionProps = Omit<HTMLAttributes<HTMLDivElement>, 'aria-label' | 'role'> & {
  /** What is loading, in words. Announced once, so a screen reader is told rather than left in silence. */
  label: string;
  children: ReactNode;
};

/**
 * A skeleton panel, announced.
 *
 * A row of grey blocks says nothing to a screen reader, so every loading state in this product carries a label
 * — and seven of them carried it on a plain `<div>`, which is **invalid**: `aria-label` is prohibited on a
 * generic element, and axe reports it as serious. It was invisible for exactly the reason it matters, since a
 * loading state is on screen for a second and nobody is auditing during that second; the gate caught it as an
 * intermittent failure on a slow run.
 *
 * `role="status"` is the fix and is better than silencing the label: it permits the name *and* makes the region
 * a polite live region, so the announcement happens when the state appears rather than only if somebody
 * navigates into it.
 */
export function LoadingRegion({ label, className, children, ...props }: LoadingRegionProps) {
  return (
    <div role="status" aria-label={label} aria-busy="true" className={cn(className)} {...props}>
      {children}
    </div>
  );
}
