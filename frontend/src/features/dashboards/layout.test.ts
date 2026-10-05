import { describe, expect, it } from 'vitest';
import { clampWidth, findSlot, GRID_COLUMNS, move, reflow, resize } from './layout';

type Placed = { column: number; row: number; width: number; height: number };

function placed(width: number, row = 0, column = 0): Placed {
  return { column, row, width, height: 2 };
}

describe('reflow', () => {
  it('packs widgets along a row and wraps when one does not fit', () => {
    const laid = reflow([placed(6), placed(6), placed(6)]);

    expect(laid.map((w) => [w.row, w.column])).toEqual([
      [0, 0],
      [0, 6],
      [1, 0],
    ]);
  });

  it('never leaves two widgets overlapping', () => {
    /*
     * The reason this function exists. An overlap does not error in a browser — one
     * widget simply covers another, and the hidden one is invisible until somebody
     * notices it is gone.
     */
    const laid = reflow([placed(8), placed(8), placed(4)]);

    for (const [index, widget] of laid.entries()) {
      for (const other of laid.slice(index + 1)) {
        const sameRow = widget.row === other.row;
        const horizontalOverlap =
          widget.column < other.column + other.width && other.column < widget.column + widget.width;

        expect(sameRow && horizontalOverlap).toBe(false);
      }
    }
  });

  it('keeps the order the reader arranged', () => {
    const laid = reflow([placed(3), placed(9), placed(12)]);

    // Positions change; sequence does not.
    expect(laid).toHaveLength(3);
    expect(laid[0]?.width).toBe(3);
    expect(laid[1]?.width).toBe(9);
    expect(laid[2]?.width).toBe(12);
  });

  it('clamps a width that would run off the grid', () => {
    const laid = reflow([placed(40)]);

    expect(laid[0]?.width).toBe(GRID_COLUMNS);
    expect(clampWidth(1)).toBe(3);
  });

  it('starts a new row for a full-width widget', () => {
    const laid = reflow([placed(6), placed(12)]);

    expect(laid[1]?.row).toBe(1);
    expect(laid[1]?.column).toBe(0);
  });
});

describe('findSlot', () => {
  it('puts a new widget beside the last one when there is room', () => {
    expect(findSlot([placed(6)], 6)).toEqual({ column: 6, row: 0 });
  });

  it('starts a new row when the current one is full', () => {
    expect(findSlot([placed(6), placed(6, 0, 6)], 6)).toEqual({ column: 0, row: 1 });
  });

  it('fills a gap left by a narrower neighbour', () => {
    // Someone resized the first widget down; the next one belongs in the space.
    expect(findSlot([placed(4)], 4)).toEqual({ column: 4, row: 0 });
  });

  it('places the first widget at the origin', () => {
    expect(findSlot([], 6)).toEqual({ column: 0, row: 0 });
  });
});

describe('move', () => {
  it('swaps a widget with its neighbour and re-flows', () => {
    const laid = reflow([placed(3), placed(9)]);
    const moved = move(laid, 0, 1);

    expect(moved[0]?.width).toBe(9);
    expect(moved[1]?.width).toBe(3);
    // Re-flowed, so the 9-wide one is first on the row and the 3-wide follows it.
    expect(moved[1]?.column).toBe(9);
  });

  it('refuses to move past either end rather than wrapping', () => {
    const laid = reflow([placed(6), placed(6)]);

    expect(move(laid, 0, -1)).toBe(laid);
    expect(move(laid, 1, 1)).toBe(laid);
  });
});

describe('resize', () => {
  it('makes room by pushing the neighbour down', () => {
    const laid = reflow([placed(6), placed(6)]);
    const resized = resize(laid, 0, { width: 12 });

    expect(resized[0]?.width).toBe(12);
    expect(resized[1]?.row).toBe(1);
  });

  it('pulls a neighbour back up when a widget narrows', () => {
    const laid = reflow([placed(12), placed(6)]);
    const resized = resize(laid, 0, { width: 6 });

    // The second widget now fits beside the first.
    expect(resized[1]?.row).toBe(0);
    expect(resized[1]?.column).toBe(6);
  });

  it('clamps a height outside the grid’s range', () => {
    const laid = reflow([placed(6)]);

    expect(resize(laid, 0, { height: 99 })[0]?.height).toBe(6);
    expect(resize(laid, 0, { height: 0 })[0]?.height).toBe(1);
  });
});
