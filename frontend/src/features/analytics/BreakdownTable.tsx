import { useTranslation } from 'react-i18next';
import { ExternalLink } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { Badge } from '@/shared/ui/badge';
import { formatMetric } from '@/shared/metrics';
import type { BreakdownRowPayload, MetricDefinition } from '@/shared/metrics';

type BreakdownTableProps = {
  rows: BreakdownRowPayload[];
  metrics: string[];
  definitions: Map<string, MetricDefinition>;
  dimensionLabel: string;
  onRowSelect?: (row: BreakdownRowPayload) => void;
  selectedKey?: string | null;
};

/**
 * A breakdown as a table.
 *
 * Deliberately a real `<table>`: a grid of divs loses the row/column relationship
 * a screen reader needs, and an analytics table is exactly where that matters.
 *
 * Numbers are end-aligned and use tabular figures, so a column of them lines up on
 * the decimal in both Arabic and English — `text-end` rather than `text-right`, so
 * the alignment flips with the direction instead of staying stuck to one side.
 */
export function BreakdownTable({
  rows,
  metrics,
  definitions,
  dimensionLabel,
  onRowSelect,
  selectedKey = null,
}: BreakdownTableProps) {
  const { t, i18n } = useTranslation();
  const isInteractive = onRowSelect !== undefined;

  return (
    <div className="border-line-subtle overflow-x-auto rounded-xl border">
      <table className="w-full border-collapse text-sm">
        <thead>
          <tr className="border-line-subtle bg-surface-sunken border-b">
            <th
              scope="col"
              className="text-content-secondary px-4 py-2.5 text-start text-xs font-semibold tracking-wide uppercase"
            >
              {dimensionLabel}
            </th>
            {metrics.map((metric) => (
              <th
                key={metric}
                scope="col"
                className="text-content-secondary px-4 py-2.5 text-end text-xs font-semibold tracking-wide whitespace-nowrap uppercase"
              >
                {definitions.get(metric)?.label ?? metric}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row.key}
              {...(isInteractive
                ? {
                    onClick: () => onRowSelect(row),
                    tabIndex: 0,
                    onKeyDown: (event: React.KeyboardEvent) => {
                      if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        onRowSelect(row);
                      }
                    },
                  }
                : {})}
              className={cn(
                'border-line-subtle border-b last:border-0',
                isInteractive &&
                  'hover:bg-surface-hover focus-visible:ring-accent/40 cursor-pointer focus-visible:ring-2 focus-visible:outline-none',
                selectedKey === row.key && 'bg-accent-subtle',
              )}
            >
              <th scope="row" className="px-4 py-2.5 text-start font-normal">
                <div className="flex flex-col gap-1">
                  <span className="text-content font-medium">{row.label}</span>
                  <div className="flex flex-wrap items-center gap-1.5">
                    {row.status !== null && (
                      <Badge tone={statusTone(row.status)} className="text-2xs px-2 py-0">
                        {t(`campaigns.status.${row.status}`, { defaultValue: row.status })}
                      </Badge>
                    )}
                    {row.external_id !== null && (
                      /*
                       * The provider's own id. An operator reconciling our screen
                       * against the platform's needs it, and it is the only way to
                       * find the same campaign there.
                       */
                      <span className="text-content-tertiary ds-numeric inline-flex items-center gap-1 text-xs">
                        <ExternalLink aria-hidden className="size-3" />
                        {row.external_id}
                      </span>
                    )}
                  </div>
                </div>
              </th>
              {metrics.map((metric) => {
                const value = row.values[metric];

                return (
                  <td
                    key={metric}
                    className="ds-numeric text-content px-4 py-2.5 text-end whitespace-nowrap"
                    // A missing figure says why on hover, rather than just showing
                    // a dash the reader has to guess at.
                    title={value?.unavailable_reason ?? undefined}
                  >
                    {value === undefined ? (
                      <span className="text-content-tertiary">—</span>
                    ) : (
                      <span className={cn(value.value === null && 'text-content-tertiary')}>
                        {formatMetric(value, i18n.language)}
                      </span>
                    )}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function statusTone(status: string): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
  switch (status) {
    case 'active':
      return 'success';
    case 'paused':
      return 'neutral';
    case 'not_delivering':
    case 'in_review':
      return 'warning';
    case 'rejected':
      return 'danger';
    case 'completed':
      return 'info';
    default:
      return 'neutral';
  }
}
