import { env } from '@/shared/config/env';

/**
 * Tell this product something happened on the page, without ever delaying or breaking it.
 *
 * `sendBeacon` with a `text/plain` body is a "simple" cross-origin request, so a destination page on another origin needs
 * no preflight; the body carries no answer a visitor typed. Every failure is swallowed on purpose: tracking is evidence,
 * never a dependency of the form working.
 */
export function beacon(payload: Record<string, unknown>): void {
  try {
    const url = `${env.apiBaseUrl}/public/track`;
    const body = JSON.stringify(payload);

    if (typeof navigator.sendBeacon === 'function' && navigator.sendBeacon(url, body)) {
      return;
    }

    void fetch(url, {
      method: 'POST',
      body,
      keepalive: true,
      headers: { 'Content-Type': 'text/plain' },
    }).catch(() => undefined);
  } catch {
    /* tracking never breaks a page */
  }
}

/** A random id for one event, 24 characters from the URL-safe alphabet. */
export function eventId(): string {
  const bytes = new Uint8Array(18);
  crypto.getRandomValues(bytes);

  return Array.from(bytes, (b) => b.toString(36).padStart(2, '0'))
    .join('')
    .slice(0, 24);
}

/**
 * Push a mapped event to the visitor's own `dataLayer`, for Tag Manager to pick up. Carries the event id and nothing a
 * visitor typed. Does nothing when the project mapped no name for the event or the page has no `dataLayer` to push to.
 */
export function pushToDataLayer(name: string | undefined, id: string): void {
  try {
    const layer = (window as unknown as { dataLayer?: unknown[] }).dataLayer;

    if (name !== undefined && name !== '') {
      const target = layer ?? ((window as unknown as { dataLayer: unknown[] }).dataLayer = []);
      target.push({ event: name, ds_event_id: id });
    }
  } catch {
    /* a data layer problem is never the visitor's */
  }
}
