import type { HTMLAttributes } from 'react';
import { cn } from '@/shared/lib/cn';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        /*
         * A card floats on the canvas: its own surface, a hairline to define the edge, and barely any shadow.
         * The separation comes from the canvas being a measurable step darker — see `--ds-canvas` — which is
         * what allows the border and shadow to be this light without the card dissolving into the page.
         */
        'bg-surface border-line-subtle rounded-2xl border shadow-xs',
        'ease-standard transition-shadow duration-[var(--ds-duration-base)]',
        className,
      )}
      {...props}
    />
  );
}

export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('flex flex-col gap-1 px-5 pt-5 pb-4 sm:px-6', className)} {...props} />;
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return (
    <h2 className={cn('text-content text-base leading-6 font-semibold', className)} {...props} />
  );
}

export function CardDescription({ className, ...props }: HTMLAttributes<HTMLParagraphElement>) {
  return (
    <p className={cn('text-content-secondary text-sm leading-relaxed', className)} {...props} />
  );
}

export function CardContent({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('px-5 pb-5 sm:px-6 sm:pb-6', className)} {...props} />;
}
