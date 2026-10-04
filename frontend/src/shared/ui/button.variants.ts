import { cva } from 'class-variance-authority';

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap font-medium ' +
    'transition-[background-color,color,box-shadow,border-color] duration-[var(--ds-duration-fast)] ' +
    'ease-standard disabled:pointer-events-none disabled:opacity-50 ' +
    '[&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        primary:
          'bg-accent text-accent-contrast shadow-xs hover:bg-accent-hover active:translate-y-px',
        secondary:
          'border border-line bg-surface text-content shadow-xs hover:bg-surface-hover active:bg-surface-active',
        ghost: 'text-content-secondary hover:bg-surface-hover hover:text-content',
        subtle: 'bg-accent-subtle text-content-brand hover:brightness-[0.98]',
        danger: 'bg-danger text-white shadow-xs hover:brightness-110',
      },
      size: {
        sm: 'h-8 rounded-md px-3 text-[0.8125rem]',
        md: 'h-9 rounded-md px-4 text-sm',
        lg: 'h-11 rounded-lg px-6 text-base',
        icon: 'size-9 rounded-md',
      },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
);
