import type { ReactNode } from 'react';
import { useId } from 'react';
import { cn } from '@/shared/lib/cn';

type FieldProps = {
  label: string;
  /** Rendered with the generated id and the error wiring already applied. */
  children: (props: {
    id: string;
    'aria-describedby': string | undefined;
    invalid: boolean;
  }) => ReactNode;
  error?: string | undefined;
  hint?: string | undefined;
  required?: boolean;
  className?: string;
};

/**
 * Label + control + message, wired for assistive technology.
 *
 * The error is announced via aria-describedby and role="alert" rather than
 * being conveyed by colour alone.
 */
export function Field({ label, children, error, hint, required, className }: FieldProps) {
  const id = useId();
  const errorId = `${id}-error`;
  const hintId = `${id}-hint`;
  const describedBy = error ? errorId : hint ? hintId : undefined;

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-content text-sm font-medium">
        {label}
        {required ? (
          <span className="text-danger ms-1" aria-hidden="true">
            *
          </span>
        ) : null}
      </label>

      {children({ id, 'aria-describedby': describedBy, invalid: Boolean(error) })}

      {error ? (
        <p id={errorId} role="alert" className="text-danger text-xs">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="text-content-tertiary text-xs">
          {hint}
        </p>
      ) : null}
    </div>
  );
}
