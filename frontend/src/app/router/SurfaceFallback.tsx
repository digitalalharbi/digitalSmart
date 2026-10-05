import { Skeleton } from '@/shared/ui/skeleton';

/**
 * Holds a surface's shape while its code arrives.
 *
 * Shaped like the page it precedes — a heading, a filter bar, a scorecard row, a
 * chart — so the layout does not jump when the real page replaces it. A generic
 * spinner would be smaller to write and worse to look at: the jump is the part
 * people notice.
 */
export function SurfaceFallback() {
  return (
    <div className="flex flex-col gap-5" aria-busy="true">
      <Skeleton className="h-8 w-56" />
      <Skeleton className="h-14 w-full" />
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        {Array.from({ length: 5 }, (_, index) => (
          <Skeleton key={index} className="h-24" />
        ))}
      </div>
      <Skeleton className="h-[320px] w-full" />
    </div>
  );
}
