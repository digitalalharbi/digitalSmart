import type { Ref, TextareaHTMLAttributes } from 'react';
import { cn } from '@/shared/lib/cn';

export type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & {
  ref?: Ref<HTMLTextAreaElement>;
  invalid?: boolean;
};

export function Textarea({ className, invalid, ...props }: TextareaProps) {
  return (
    <textarea
      aria-invalid={invalid ? true : undefined}
      className={cn(
        'border-line bg-surface text-content placeholder:text-content-tertiary min-h-20 w-full rounded-md border px-3 py-2 text-sm',
        'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
        'disabled:cursor-not-allowed disabled:opacity-50',
        invalid && 'border-danger',
        className,
      )}
      {...props}
    />
  );
}
