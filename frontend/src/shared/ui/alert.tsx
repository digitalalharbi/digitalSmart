import type { HTMLAttributes } from 'react';
import { cn } from '@/shared/lib/cn';

type AlertProps = HTMLAttributes<HTMLDivElement> & {
  tone?: 'danger' | 'warning' | 'info';
};

const TONES: Record<NonNullable<AlertProps['tone']>, string> = {
  danger: 'border-danger/30 bg-danger-surface text-content',
  warning: 'border-warning/30 bg-warning-surface text-content',
  info: 'border-info/30 bg-info-surface text-content',
};

export function Alert({ className, tone = 'info', ...props }: AlertProps) {
  return (
    <div
      role="alert"
      className={cn('rounded-lg border px-4 py-3 text-sm', TONES[tone], className)}
      {...props}
    />
  );
}
