import { cva } from 'class-variance-authority';

export const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
  {
    variants: {
      tone: {
        neutral: 'bg-surface-sunken text-content-secondary border border-line-subtle',
        accent: 'bg-accent-subtle text-content-brand',
        success: 'bg-success-surface text-success',
        warning: 'bg-warning-surface text-warning',
        danger: 'bg-danger-surface text-danger',
        info: 'bg-info-surface text-info',
      },
    },
    defaultVariants: { tone: 'neutral' },
  },
);
