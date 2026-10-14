import { cva } from 'class-variance-authority';

/**
 * The product's status pill.
 *
 * Soft tinted background, readable text, no saturated fills — a badge is a label, not a button, and a row of
 * loud ones turns a table into a traffic light. Every status in the product goes through this: client and
 * project status, creative state, sync freshness, honesty labels.
 */
export const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium leading-none',
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
