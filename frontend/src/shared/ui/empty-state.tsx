import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/cn';

type EmptyStateProps = {
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
};

export function EmptyState({ title, description, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        'border-line-subtle flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-14 text-center',
        className,
      )}
    >
      <p className="text-content text-sm font-medium">{title}</p>
      {description ? (
        <p className="text-content-secondary max-w-sm text-sm">{description}</p>
      ) : null}
      {action}
    </div>
  );
}
