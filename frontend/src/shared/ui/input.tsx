import type { InputHTMLAttributes, Ref } from 'react';
import { cn } from '@/shared/lib/cn';

export type InputProps = InputHTMLAttributes<HTMLInputElement> & {
  ref?: Ref<HTMLInputElement>;
  invalid?: boolean;
};

export function Input({ className, invalid, ...props }: InputProps) {
  return (
    <input
      aria-invalid={invalid ? true : undefined}
      className={cn(
        'border-line-subtle bg-surface text-content placeholder:text-content-tertiary h-10 w-full rounded-full border px-4 text-sm',
        /* On a touch screen: a 44px target, and 16px text — anything smaller makes iOS Safari zoom the page on focus. */
        'max-md:h-11 max-md:text-[16px] pointer-coarse:h-11 pointer-coarse:text-[16px]',
        'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
        'disabled:cursor-not-allowed disabled:opacity-50',
        invalid && 'border-danger',
        className,
      )}
      {...props}
    />
  );
}
