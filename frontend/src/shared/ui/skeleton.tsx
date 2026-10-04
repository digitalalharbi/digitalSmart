import type { HTMLAttributes } from 'react';
import { cn } from '@/shared/lib/cn';

/** Loading placeholder. Pulse is suppressed under prefers-reduced-motion by base CSS. */
export function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      aria-hidden="true"
      className={cn('bg-surface-sunken animate-pulse rounded-md', className)}
      {...props}
    />
  );
}
