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
        'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
        'disabled:cursor-not-allowed disabled:opacity-50',
        invalid && 'border-danger',
        className,
      )}
      {...props}
    />
  );
}
