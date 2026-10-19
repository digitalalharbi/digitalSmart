/** Reading a finding's evidence without trusting its shape: a missing or non-numeric figure is null, never zero. */
export function num(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

export function str(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

/** Only a same-origin path is rendered as an image. */
export function preview(value: unknown): string | null {
  const path = str(value);

  return path !== null && path.startsWith('/') && !path.startsWith('//') ? path : null;
}

export function record(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;
}
