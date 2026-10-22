import { useId } from 'react';

/**
 * A labelled choice for a filter bar: a native `<select>`, because on a phone the platform's own picker is the best
 * one there is, and a label bound by `htmlFor` so the control's accessible name is its label and nothing else.
 *
 * `any` is the "no filter" option's text; pass `null` where there is no such option (a sort order, say).
 */
export function FilterSelect({
  label,
  value,
  onChange,
  options,
  any,
}: {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
  options: Array<{ value: string; label: string }>;
  any: string | null;
}) {
  const id = useId();

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-content-secondary text-xs font-medium">
        {label}
      </label>
      <select
        id={id}
        value={value ?? ''}
        onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        className="bg-surface-sunken text-content h-10 rounded-full border-0 px-4 text-sm"
      >
        {any === null ? null : <option value="">{any}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}
