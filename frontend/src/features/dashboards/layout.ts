import type { WidgetDraft, WidgetPayload } from './types';

/**
 * Grid arithmetic for the dashboard canvas.
 *
 * Pure functions, deliberately: the editor's reorder, resize and insert all reduce
 * to rearranging a list of placements, and keeping that arithmetic out of the
 * components means it can be tested exhaustively without rendering anything.
 *
 * The server validates the same rules. These exist so the editor can prevent an
 * invalid layout rather than let a reader compose one and be refused on save.
 */
export const GRID_COLUMNS = 12;
export const MIN_WIDTH = 3;
export const MIN_HEIGHT = 1;
export const MAX_HEIGHT = 6;

/** The widths offered as presets, because arbitrary pixel dragging is not the point. */
export const WIDTH_PRESETS = [3, 4, 6, 8, 9, 12] as const;

export function clampWidth(width: number): number {
  return Math.min(GRID_COLUMNS, Math.max(MIN_WIDTH, Math.round(width)));
}

export function clampHeight(height: number): number {
  return Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, Math.round(height)));
}

type Placed = { column: number; row: number; width: number; height: number };

function overlaps(a: Placed, b: Placed): boolean {
  if (a.row !== b.row) {
    return false;
  }

  return a.column < b.column + b.width && b.column < a.column + a.width;
}

/**
 * Where a new widget of this width fits.
 *
 * Scans rows for the first gap wide enough, which is what someone adding a widget
 * expects: beside the last one if there is room, on a new row if there is not.
 */
export function findSlot(widgets: Placed[], width: number): { column: number; row: number } {
  const w = clampWidth(width);
  const lastRow = widgets.reduce((max, widget) => Math.max(max, widget.row), -1);

  for (let row = 0; row <= lastRow + 1; row += 1) {
    const onRow = widgets.filter((widget) => widget.row === row);

    for (let column = 0; column + w <= GRID_COLUMNS; column += 1) {
      const candidate = { column, row, width: w, height: 1 };

      if (!onRow.some((widget) => overlaps(candidate, widget))) {
        return { column, row };
      }
    }
  }

  return { column: 0, row: lastRow + 1 };
}

/**
 * Re-flow a layout so nothing overlaps and no row is left empty.
 *
 * Called after every reorder and resize. Without it, widening a widget would push
 * its neighbour into the same cells — and an overlap does not error in a browser,
 * it just hides one widget behind another until someone notices it is gone.
 *
 * Order is preserved: the list the reader sees is the list they arranged.
 */
export function reflow<T extends Placed>(widgets: T[]): T[] {
  const result: T[] = [];
  let row = 0;
  let column = 0;

  for (const widget of widgets) {
    const width = clampWidth(widget.width);

    // Does not fit on the current row, so start a new one.
    if (column + width > GRID_COLUMNS) {
      row += 1;
      column = 0;
    }

    result.push({ ...widget, column, row, width, height: clampHeight(widget.height) });
    column += width;

    if (column >= GRID_COLUMNS) {
      row += 1;
      column = 0;
    }
  }

  return result;
}

/** Move a widget one place earlier or later, then re-flow. */
export function move<T extends Placed>(widgets: T[], index: number, delta: number): T[] {
  const target = index + delta;

  if (index < 0 || index >= widgets.length || target < 0 || target >= widgets.length) {
    return widgets;
  }

  const reordered = [...widgets];
  const [moved] = reordered.splice(index, 1);

  if (moved === undefined) {
    return widgets;
  }

  reordered.splice(target, 0, moved);

  return reflow(reordered);
}

/** Resize a widget, then re-flow so its neighbours make room. */
export function resize<T extends Placed>(
  widgets: T[],
  index: number,
  size: { width?: number; height?: number },
): T[] {
  const next = widgets.map((widget, position) =>
    position === index
      ? {
          ...widget,
          width: size.width === undefined ? widget.width : clampWidth(size.width),
          height: size.height === undefined ? widget.height : clampHeight(size.height),
        }
      : widget,
  );

  return reflow(next);
}

/** A stable key for a widget being edited, before the server assigns an id. */
export function draftKey(): string {
  return `draft-${Math.random().toString(36).slice(2, 10)}`;
}

/** Turn a saved widget into an editable draft. */
export function toDraft(widget: WidgetPayload): WidgetDraft {
  return {
    key: widget.id,
    title: widget.title,
    type: widget.type,
    metrics: widget.metrics,
    dimension: widget.dimension,
    filter_overrides: widget.filter_overrides,
    column: widget.column,
    row: widget.row,
    width: widget.width,
    height: widget.height,
    options: widget.options,
  };
}
