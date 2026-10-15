import { cn } from '@/shared/lib/cn';

/** Six states as one stacked bar, each segment a button that narrows the list to it. */
export function HealthDistribution<K extends string>({
  counts,
  selected = null,
  onSelect,
  order,
  labels,
}: {
  counts: Record<K, number>;
  selected?: K | null;
  /** Omit to render a read-only distribution: segments become plain labels, not buttons. */
  onSelect?: ((health: K | null) => void) | undefined;
  order: K[];
  labels: Record<K, string>;
}) {
  const total = order.reduce((sum, key) => sum + counts[key], 0);
  const tone: Record<string, string> = {
    healthy: 'bg-success',
    watch: 'bg-warning',
    needs_attention: 'bg-warning',
    critical: 'bg-danger',
    inactive: 'bg-content-tertiary',
    insufficient_data: 'bg-line',
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="bg-surface-sunken flex h-3 w-full overflow-hidden rounded-full" aria-hidden>
        {order.map((key) =>
          counts[key] === 0 || total === 0 ? null : (
            <span
              key={key}
              className={cn(tone[key] ?? 'bg-line', 'h-full')}
              style={{ width: `${String((counts[key] / total) * 100)}%` }}
            />
          ),
        )}
      </div>
      <ul className="flex flex-wrap gap-2">
        {order.map((key) => (
          <li key={key}>
            <button
              type="button"
              aria-pressed={onSelect === undefined ? undefined : selected === key}
              disabled={onSelect === undefined}
              onClick={() => onSelect?.(selected === key ? null : key)}
              className={cn(
                'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium',
                selected === key
                  ? 'border-accent bg-accent-subtle text-content-brand'
                  : 'border-line text-content-secondary hover:bg-surface-hover',
              )}
            >
              <span aria-hidden className={cn('size-2 rounded-full', tone[key] ?? 'bg-line')} />
              {labels[key]}
              <span className="ds-numeric">{counts[key]}</span>
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
