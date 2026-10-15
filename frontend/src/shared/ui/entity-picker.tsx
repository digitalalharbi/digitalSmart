import { useId, useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { Input } from '@/shared/ui/input';

export type PickerOption = { id: string; label: string; context?: string | null | undefined };

/**
 * Choose one entity out of hundreds without a hundred-item `<select>`.
 *
 * A search box with a short result list under it. Nothing is loaded until the reader types, and what
 * arrives is the server's top few — which is the only shape that stays usable at a hundred projects.
 * The chosen value is shown as a removable chip, so "what is this filtered to" is never a question.
 */
export function EntityPicker({
  label,
  value,
  onChange,
  options,
  onSearch,
  isLoading = false,
  placeholder,
  clearLabel,
}: {
  label: string;
  value: PickerOption | null;
  onChange: (option: PickerOption | null) => void;
  options: PickerOption[];
  onSearch: (term: string) => void;
  isLoading?: boolean;
  placeholder?: string | undefined;
  clearLabel: string;
}) {
  const [term, setTerm] = useState('');
  const [open, setOpen] = useState(false);
  const listId = useId();

  if (value !== null) {
    return (
      <div className="flex flex-col gap-1">
        <span className="text-content-secondary text-xs font-medium">{label}</span>
        <span className="bg-accent-subtle text-content-brand inline-flex h-9 items-center gap-2 rounded-md px-3 text-sm">
          <span className="truncate">{value.label}</span>
          <button
            type="button"
            aria-label={`${clearLabel}: ${value.label}`}
            onClick={() => {
              onChange(null);
              setTerm('');
            }}
            className="hover:text-content rounded-sm"
          >
            <X aria-hidden className="size-3.5" />
          </button>
        </span>
      </div>
    );
  }

  return (
    <div className="relative flex flex-col gap-1">
      <label htmlFor={`${listId}-input`} className="text-content-secondary text-xs font-medium">
        {label}
      </label>
      <Input
        id={`${listId}-input`}
        role="combobox"
        aria-expanded={open && term.trim().length >= 2}
        aria-controls={listId}
        aria-autocomplete="list"
        autoComplete="off"
        placeholder={placeholder}
        value={term}
        onChange={(event) => {
          setTerm(event.target.value);
          setOpen(true);
          onSearch(event.target.value);
        }}
        onBlur={() => window.setTimeout(() => setOpen(false), 120)}
        onFocus={() => setOpen(true)}
      />
      {open && term.trim().length >= 2 ? (
        <ul
          id={listId}
          role="listbox"
          aria-label={label}
          className="bg-surface border-line absolute top-full z-30 mt-1 max-h-60 w-full min-w-48 overflow-auto rounded-lg border p-1 shadow-md"
        >
          {isLoading ? (
            <li className="text-content-tertiary px-3 py-2 text-sm">…</li>
          ) : options.length === 0 ? (
            <li className="text-content-tertiary px-3 py-2 text-sm">—</li>
          ) : (
            options.map((option) => (
              <li
                key={option.id}
                role="option"
                aria-selected={false}
                tabIndex={0}
                className={cn(
                  'hover:bg-surface-hover focus-visible:bg-surface-hover cursor-pointer rounded-md px-3 py-2 text-sm outline-none',
                )}
                onMouseDown={(event) => {
                  event.preventDefault();
                  onChange(option);
                  setOpen(false);
                }}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    onChange(option);
                    setOpen(false);
                  }
                }}
              >
                <span className="text-content block truncate">{option.label}</span>
                {option.context ? (
                  <span className="text-content-tertiary block truncate text-xs">
                    {option.context}
                  </span>
                ) : null}
              </li>
            ))
          )}
        </ul>
      ) : null}
    </div>
  );
}
