import type { VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes, ReactNode, Ref } from 'react';
import { cn } from '@/shared/lib/cn';
import { buttonVariants } from './button.variants';

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> &
  VariantProps<typeof buttonVariants> & {
    children?: ReactNode;
    /** React 19 passes refs as a normal prop; no forwardRef needed. */
    ref?: Ref<HTMLButtonElement>;
  };

export function Button({ className, variant, size, type = 'button', ...props }: ButtonProps) {
  return (
    <button type={type} className={cn(buttonVariants({ variant, size }), className)} {...props} />
  );
}
