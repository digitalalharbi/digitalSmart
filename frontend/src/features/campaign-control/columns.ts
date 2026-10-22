/**
 * The campaign table's columns, and which of them a person has chosen to see.
 *
 * Seventeen columns do not fit one screen and are not all wanted by one person: a media buyer reads cost and
 * return, a manager reads client and dates. So what is shown is the person's choice, kept in this browser —
 * a convenience, never state anything depends on, which is why every read and write is allowed to fail.
 */
export const COLUMN_IDS = [
  'platform',
  'client',
  'project',
  'status',
  'objective',
  'budget',
  'spend',
  'results',
  'cpa',
  'revenue',
  'roas',
  'ctr',
  'start',
  'end',
  'health',
  'trend',
  'sync',
] as const;

export type ColumnId = (typeof COLUMN_IDS)[number];

/** Out of the way until asked for: the first screen answers "which campaign, how is it doing", not everything. */
export const DEFAULT_HIDDEN: readonly ColumnId[] = ['objective', 'revenue', 'start', 'end', 'sync'];

export type TableSettings = { hidden: ColumnId[]; dense: boolean };

const KEY = 'digitalsmart.campaigns.table';

export const DEFAULT_SETTINGS: TableSettings = { hidden: [...DEFAULT_HIDDEN], dense: false };

export function readSettings(): TableSettings {
  try {
    const raw = window.localStorage.getItem(KEY);

    if (raw === null) {
      return DEFAULT_SETTINGS;
    }

    const parsed = JSON.parse(raw) as { hidden?: unknown; dense?: unknown };
    const hidden = Array.isArray(parsed.hidden)
      ? parsed.hidden.filter((id): id is ColumnId => COLUMN_IDS.includes(id as ColumnId))
      : [...DEFAULT_HIDDEN];

    return { hidden, dense: parsed.dense === true };
  } catch {
    return DEFAULT_SETTINGS;
  }
}

export function writeSettings(settings: TableSettings): void {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(settings));
  } catch {
    /* A browser that will not remember still shows the table. */
  }
}
