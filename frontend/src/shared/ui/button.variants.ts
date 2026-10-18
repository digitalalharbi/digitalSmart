import { cva } from 'class-variance-authority';

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 whitespace-nowrap font-medium ' +
    'transition-[background-color,color,box-shadow,border-color] duration-[var(--ds-duration-fast)] ' +
    'ease-standard disabled:pointer-events-none disabled:opacity-50 ' +
    '[&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        primary: 'bg-action text-action-contrast hover:bg-action-hover active:translate-y-px',
        secondary:
          'bg-surface-sunken text-content hover:bg-surface-active active:bg-surface-active',
        ghost: 'text-content-secondary hover:bg-surface-hover hover:text-content',
        subtle: 'bg-accent-subtle text-content-brand hover:brightness-[0.98]',
        danger: 'bg-danger text-white shadow-xs hover:brightness-110',
      },
      /*
       * Pills rather than rounded rectangles, and one step taller at every size. Two changes, one reason: a
       * control that reads as a distinct object needs a shape the eye can separate from the card behind it,
       * and the references all do this. `icon` stays square-ish so a row of them reads as one cluster.
       */
      size: {
        sm: 'h-8 rounded-full px-3.5 text-[0.8125rem]',
        md: 'h-10 rounded-full px-4.5 text-sm',
        lg: 'h-12 rounded-full px-6 text-base',
        icon: 'size-9 rounded-full',
      },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
);
