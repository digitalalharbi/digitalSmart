import { useTranslation } from 'react-i18next';
import { ChevronDown, ChevronUp, Filter, Maximize2, Minimize2, Pencil, Trash2 } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { Badge } from '@/shared/ui/badge';
import { DashboardWidget } from './DashboardWidget';
import { GRID_COLUMNS, MIN_WIDTH } from './layout';
import type { DashboardFilters, WidgetDraft } from './types';

type DashboardCanvasProps = {
  widgets: WidgetDraft[];
  filters: DashboardFilters;
  /** Editing controls appear only when the reader may change this dashboard. */
  isEditing?: boolean;
  onMove?: (index: number, delta: number) => void;
  onResize?: (index: number, size: { width?: number; height?: number }) => void;
  onEdit?: (index: number) => void;
  onRemove?: (index: number) => void;
};

/**
 * The widget canvas.
 *
 * A CSS grid rather than absolute positioning, because the layout has to survive a
 * phone: the server's 12-column placement is honoured from `md` upward, and below
 * that every widget becomes full width in the order it was arranged. A dashboard
 * that can only be read on a desktop is a dashboard a media buyer cannot check from
 * a client's office.
 *
 * Reordering and resizing are buttons, not drag-and-drop. That is a deliberate
 * choice rather than a shortcut: drag-only reordering fails WCAG 2.2's dragging
 * criterion, does not work with a keyboard, and is awkward on touch — which is most
 * of where this will be read. Pointer dragging can be layered on top of the same
 * model later without changing it.
 */
export function DashboardCanvas({
  widgets,
  filters,
  isEditing = false,
  onMove,
  onResize,
  onEdit,
  onRemove,
}: DashboardCanvasProps) {
  const { t } = useTranslation();

  return (
    <div
      className="grid gap-4"
      style={{ gridTemplateColumns: `repeat(${GRID_COLUMNS}, minmax(0, 1fr))` }}
    >
      {widgets.map((widget, index) => (
        <section
          key={widget.key}
          className={cn(
            'bg-surface border-line-subtle flex flex-col rounded-2xl border',
            // Full width below md; the arranged placement from md upward.
            'col-span-full',
          )}
          style={{
            // Grid column spans are set inline because they are data, not design:
            // a reader chose them, and Tailwind cannot enumerate every combination.
            // The `md` breakpoint is applied via a CSS custom property below.
            ['--widget-span' as string]: String(widget.width),
            ['--widget-rows' as string]: String(widget.height),
          }}
          data-widget-span={widget.width}
          aria-label={widget.title ?? t(`dashboards.widgetTypes.${widget.type}`)}
        >
          <header className="border-line-subtle flex items-start justify-between gap-2 border-b px-4 py-2.5">
            <div className="flex min-w-0 flex-col gap-0.5">
              <h3 className="text-content truncate text-sm font-semibold">
                {widget.title ?? t(`dashboards.widgetTypes.${widget.type}`)}
              </h3>
              <div className="flex flex-wrap items-center gap-1.5">
                <span className="text-content-tertiary text-xs">
                  {t(`dashboards.widgetTypes.${widget.type}`)}
                  {widget.dimension !== null &&
                    ` · ${t(`analytics.dimensions.${widget.dimension}`)}`}
                </span>
                {/*
                  Flagged when a widget asks a different question from the rest of the
                  canvas, so a reader comparing two tiles knows why they disagree.
                */}
                {widget.filter_overrides !== null && (
                  <Badge tone="info" className="text-2xs gap-1 px-1.5 py-0">
                    <Filter aria-hidden className="size-2.5" />
                    {t('dashboards.ownFilters')}
                  </Badge>
                )}
              </div>
            </div>

            {isEditing && (
              <div className="flex shrink-0 items-center gap-0.5">
                <IconButton
                  label={t('dashboards.canvas.moveEarlier')}
                  onClick={() => onMove?.(index, -1)}
                  disabled={index === 0}
                >
                  <ChevronUp aria-hidden className="size-4" />
                </IconButton>
                <IconButton
                  label={t('dashboards.canvas.moveLater')}
                  onClick={() => onMove?.(index, 1)}
                  disabled={index === widgets.length - 1}
                >
                  <ChevronDown aria-hidden className="size-4" />
                </IconButton>
                <IconButton
                  label={t('dashboards.canvas.narrower')}
                  onClick={() => onResize?.(index, { width: widget.width - 3 })}
                  disabled={widget.width <= MIN_WIDTH}
                >
                  <Minimize2 aria-hidden className="size-4" />
                </IconButton>
                <IconButton
                  label={t('dashboards.canvas.wider')}
                  onClick={() => onResize?.(index, { width: widget.width + 3 })}
                  disabled={widget.width >= GRID_COLUMNS}
                >
                  <Maximize2 aria-hidden className="size-4" />
                </IconButton>
                <IconButton label={t('dashboards.canvas.edit')} onClick={() => onEdit?.(index)}>
                  <Pencil aria-hidden className="size-4" />
                </IconButton>
                <IconButton
                  label={t('dashboards.canvas.remove')}
                  onClick={() => onRemove?.(index)}
                  tone="danger"
                >
                  <Trash2 aria-hidden className="size-4" />
                </IconButton>
              </div>
            )}
          </header>

          <div
            className="min-h-0 flex-1 px-4 py-3"
            style={{ minHeight: `${widget.height * 5.5}rem` }}
          >
            <DashboardWidget widget={widget} inherited={filters} />
          </div>
        </section>
      ))}
    </div>
  );
}

function IconButton({
  label,
  onClick,
  disabled = false,
  tone = 'neutral',
  children,
}: {
  label: string;
  onClick: () => void;
  disabled?: boolean;
  tone?: 'neutral' | 'danger';
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      // The visible control is an icon, so the accessible name has to be explicit.
      aria-label={label}
      title={label}
      className={cn(
        'focus-visible:ring-accent/40 rounded-md p-1.5 focus-visible:ring-2 focus-visible:outline-none',
        'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
        'disabled:pointer-events-none disabled:opacity-35',
        tone === 'danger'
          ? 'text-content-secondary hover:bg-danger-surface hover:text-danger'
          : 'text-content-secondary hover:bg-surface-hover hover:text-content',
      )}
    >
      {children}
    </button>
  );
}
