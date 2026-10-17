import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

type EmptyStateProps = {
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
  /**
   * Render the title as a heading of this level. A page that has nothing else to say — access refused, nothing
   * set up — must still have a first-level heading, or a screen reader lands in an unnamed `main`.
   */
  level?: 1 | 2 | 3;
};

export function EmptyState({ title, description, action, className, level }: EmptyStateProps) {
  const Title = level === undefined ? 'p' : (`h${String(level)}` as 'h1' | 'h2' | 'h3');

  return (
    <div
      className={cn(
        'border-line-subtle flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center',
        className,
      )}
    >
      <Title className="text-content text-sm font-medium">{title}</Title>
      {description ? (
        <p className="text-content-secondary max-w-sm text-sm">{description}</p>
      ) : null}
      {action}
    </div>
  );
}
