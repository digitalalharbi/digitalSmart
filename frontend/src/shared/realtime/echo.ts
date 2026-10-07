import type Echo from 'laravel-echo';
import { env } from '@/shared/config/env';

/**
 * The realtime connection: created once, and only when there is something to connect to.
 *
 * **Realtime is optional in this product, and that is reflected here rather than assumed.** `env.reverb` is null
 * without a key, so `connectRealtime()` returns null, nothing subscribes, and the surfaces say live updates are
 * off. Every notification is already written to the database and arrives on the next read — later, never
 * missing. The alternatives are both worse: throwing would break a page over a feature it does not need, and
 * attempting a connection anyway would fill every developer's console with retrying socket errors until nobody
 * reads the console.
 *
 * Echo and Pusher are imported **dynamically**, so a build that never connects never pays for them. Together
 * they are a meaningful fraction of this application, and loading them on the sign-in screen to support a bell
 * would be the wrong trade.
 */
type EchoClient = Echo<'reverb'>;

let client: EchoClient | null = null;
let attempted = false;

export function isRealtimeConfigured(): boolean {
  return env.reverb !== null;
}

/**
 * The connection, or null when realtime is not configured.
 *
 * Idempotent: the first caller creates it and the rest share it. One socket per tab — a second connection would
 * double every delivery and halve nothing.
 */
export async function connectRealtime(): Promise<EchoClient | null> {
  if (client !== null) {
    return client;
  }

  const reverb = env.reverb;

  if (attempted || reverb === null) {
    return null;
  }

  attempted = true;

  try {
    const [{ default: EchoConstructor }, { default: Pusher }] = await Promise.all([
      import('laravel-echo'),
      import('pusher-js'),
    ]);

    client = new EchoConstructor({
      broadcaster: 'reverb',
      key: reverb.key,
      wsHost: reverb.host,
      wsPort: reverb.port,
      wssPort: reverb.port,
      forceTLS: reverb.scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      /*
       * Authorisation goes through the application's own endpoint, with the session cookie. The channel is
       * private and per person, and `/broadcasting/auth` re-checks identity and active membership on every
       * subscription — so a socket cannot outlive the access that opened it.
       */
      authEndpoint: '/broadcasting/auth',
      Pusher,
    });

    return client;
  } catch {
    /*
     * Swallowed deliberately, and without a console error. A failed connection means the bell polls instead of
     * being pushed to, which the interface already says out loud; shouting here would train people to ignore a
     * console they need for real failures.
     */
    return null;
  }
}

/** Close the connection. Called on sign-out: a socket left open outlives the session that authorised it. */
export function disconnectRealtime(): void {
  client?.disconnect();
  client = null;
  attempted = false;
}
