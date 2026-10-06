import type { Page } from '@playwright/test';

/**
 * Demo accounts created by DemoWorkspaceSeeder.
 *
 * The suite exercises real roles rather than a single superuser, so a
 * permission regression fails here as well as in the backend tests.
 */
export const ACCOUNTS = {
  owner: 'owner@demo.digitalsmart.test',
  manager: 'manager@demo.digitalsmart.test',
  buyer: 'buyer@demo.digitalsmart.test',
  creative: 'creative@demo.digitalsmart.test',
  client: 'client@demo.digitalsmart.test',
} as const;

export const DEMO_PASSWORD = 'password';

/**
 * Sign in, retrying only when the connection was actually dropped.
 *
 * Two different failures look alike from the outside and must not be treated alike:
 *
 *  - **Slow.** Each sign-in costs a bcrypt verification, and two browser projects queue
 *    behind one development server, so a login can legitimately take many seconds. That
 *    is waited out, not retried — retrying a request that is still in flight just adds
 *    load to the thing that is already busy.
 *  - **Dropped.** The development server can refuse a connection outright when the host is
 *    starved — a machine running another project's test suite at the same time will do it.
 *    The application reports that correctly — "the server could not be reached" — which is
 *    the behaviour we want and which the suite asserts elsewhere. What it cannot do is turn
 *    a request that never arrived into a permission result, so the attempt is made again.
 *
 * The two are told apart by racing the navigation against that alert, so the dropped case
 * retries at once instead of waiting out a timeout it will never satisfy. A real refusal
 * renders a validation error instead and still fails the test.
 *
 * Retries **back off**, because they must. An immediate second attempt lands in the same
 * congested moment that dropped the first, which is exactly what was observed before this
 * delay existed: two refusals a second apart while the server's own log showed it
 * answering a thousand other requests.
 */
const RETRY_DELAYS_MS = [400, 1_200];

/** What the browser made of the sign-in requests, for the message if every attempt fails. */
type SignInEvidence = {
  responses: string[];
  failures: string[];
};

/**
 * Watch the sign-in requests, so a failure says what happened rather than only that it did.
 *
 * Added after two separate investigations of this exact error reached the wrong conclusion from
 * the server's own log, which under a forked development server mis-pairs request timings and
 * reports a bcrypt verification as taking 0.02ms. The browser's view is the one that matters, and
 * it costs nothing to keep.
 */
function watchSignIn(page: Page): SignInEvidence {
  const evidence: SignInEvidence = { responses: [], failures: [] };
  const isAuth = (url: string): boolean => url.includes('/auth/login') || url.includes('/sanctum/');

  page.on('response', (response) => {
    if (isAuth(response.url())) {
      evidence.responses.push(`${String(response.status())} ${new URL(response.url()).pathname}`);
    }
  });

  page.on('requestfailed', (request) => {
    if (isAuth(request.url())) {
      evidence.failures.push(
        `${new URL(request.url()).pathname}: ${request.failure()?.errorText ?? 'unknown'}`,
      );
    }
  });

  return evidence;
}

export async function signIn(page: Page, email: string): Promise<void> {
  const evidence = watchSignIn(page);

  for (let attempt = 0; attempt <= RETRY_DELAYS_MS.length; attempt += 1) {
    await page.goto('/login');

    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(DEMO_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();

    const unreachable = page.getByText(/server could not be reached/i);

    const outcome = await Promise.race([
      page
        .waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 20_000 })
        .then(() => 'signed-in' as const),
      unreachable.waitFor({ state: 'visible', timeout: 20_000 }).then(() => 'unreachable' as const),
    ]);

    if (outcome === 'signed-in') {
      return;
    }

    const delay = RETRY_DELAYS_MS[attempt];

    if (delay === undefined) {
      throw new Error(
        [
          `Sign-in for ${email} could not reach the server after ${String(RETRY_DELAYS_MS.length + 1)} attempts.`,
          `Responses: ${evidence.responses.length === 0 ? 'none' : evidence.responses.join(', ')}`,
          `Failed requests: ${evidence.failures.length === 0 ? 'none' : evidence.failures.join('; ')}`,
        ].join('\n'),
      );
    }

    await page.waitForTimeout(delay);
  }
}
