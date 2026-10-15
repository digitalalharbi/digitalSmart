import axios, { type AxiosError, type AxiosInstance, type InternalAxiosRequestConfig } from 'axios';
import { env } from '@/shared/config/env';

/** Normalised API error shape every caller can rely on. */
export class ApiError extends Error {
  readonly status: number | null;
  readonly errors: Record<string, string[]>;
  readonly isAuthError: boolean;
  /**
   * The response body, for the refusals that carry more than a sentence.
   *
   * Some of this product's refusals are *the answer*: asking for revenue without naming who counted it comes back
   * with the authorities the surface will accept, and asking for an attribution model this product cannot compute
   * comes back with what is missing. A caller that could only read `message` would have to re-fetch a vocabulary
   * the refusal already handed it.
   */
  readonly payload: Record<string, unknown> | null;

  constructor(
    message: string,
    status: number | null,
    errors: Record<string, string[]> = {},
    isAuthError = false,
    payload: Record<string, unknown> | null = null,
  ) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
    this.isAuthError = isAuthError;
    this.payload = payload;
  }

  /** First validation message for a field, if the server reported one. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

type LaravelErrorBody = {
  message?: string;
  errors?: Record<string, string[]>;
};

declare module 'axios' {
  interface AxiosRequestConfig {
    /**
     * Whether this request may be sent again if the connection fails with no response.
     *
     * Defaults to true for GET and HEAD. Set it on a write only where repeating the request
     * cannot repeat its effect.
     */
    retryOnNetworkError?: boolean;
    /** Set by the client; not for callers. */
    networkAttempts?: number;
  }
}

/**
 * How many times a request with **no response at all** is tried again.
 *
 * A request that came back with a status — even a 500 — is an answer, and answers are never
 * retried. This covers the other case: the connection failed and nothing arrived. That happens
 * on a dropped mobile connection, when a laptop changes network mid-request, and behind any
 * proxy that recycles a connection a moment before it is used.
 *
 * One retry, after a short pause. More would turn a real outage into a slow page, and the
 * difference between "the server is down" and "that request was unlucky" is almost always
 * settled by the second attempt.
 */
const NETWORK_RETRIES = 1;
const NETWORK_RETRY_DELAY_MS = 250;

/** Requests that may be repeated without repeating their effect. */
function isRepeatable(config: InternalAxiosRequestConfig | undefined): boolean {
  if (config === undefined) {
    return false;
  }

  /*
   * An explicit opt-in wins, for the handful of writes that are genuinely idempotent — signing
   * in twice signs you in once. Everything else is judged by its method: a reader can be
   * repeated, and a write that nothing has told us is safe cannot, because a request that
   * failed without a response may still have been received and acted on.
   */
  if (config.retryOnNetworkError !== undefined) {
    return config.retryOnNetworkError;
  }

  const method = (config.method ?? 'get').toLowerCase();

  return method === 'get' || method === 'head';
}

/** Called when the session is no longer valid, so the app can route to login. */
type UnauthenticatedHandler = () => void;

let onUnauthenticated: UnauthenticatedHandler = () => undefined;

export function setUnauthenticatedHandler(handler: UnauthenticatedHandler): void {
  onUnauthenticated = handler;
}

function readXsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : null;
}

export const api: AxiosInstance = axios.create({
  baseURL: env.apiBaseUrl,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

/**
 * Request interceptor: attach the locale and the CSRF token Sanctum expects for
 * stateful SPA requests.
 */
/**
 * Everything in flight, as one abortable group.
 *
 * Signing out has to be able to say "nothing that started before this may land after it". A response to a request
 * that began while the person was signed in carries a `Set-Cookie` for the session it read; arriving after the
 * sign-out has replaced that cookie, it puts the old, authenticated session back — and the reader is signed in
 * again behind a login screen. It happened one run in four under load, and the cause is not the server being
 * slow: it is a late answer overwriting a newer cookie.
 *
 * Aborting the group before the sign-out is sent means the browser discards those responses instead of applying
 * them. Requests made afterwards get a fresh controller.
 */
let inFlight = new AbortController();

export function abortInFlightRequests(): void {
  inFlight.abort();
  inFlight = new AbortController();
}

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  config.signal ??= inFlight.signal;

  const locale = document.documentElement.lang;
  if (locale) {
    config.headers.set('Accept-Language', locale);
  }

  const token = readXsrfToken();
  if (token) {
    config.headers.set('X-XSRF-TOKEN', token);
  }

  return config;
});

/**
 * Response interceptor: translate transport errors into a single ApiError type
 * so no component ever inspects raw Axios internals.
 */
api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    /*
     * A request this client withdrew is not a failure to report and not one to repeat: it was cancelled on
     * purpose, usually because the person is leaving.
     */
    if (axios.isCancel(error)) {
      return Promise.reject(new ApiError('The request was cancelled.', null));
    }

    if (!axios.isAxiosError(error)) {
      return Promise.reject(
        error instanceof Error
          ? new ApiError(error.message, null)
          : new ApiError('Unknown error', null),
      );
    }

    const axiosError = error as AxiosError<LaravelErrorBody>;
    const status = axiosError.response?.status ?? null;

    /*
     * No status means nothing came back. Try once more before telling the reader the server is
     * unreachable, which is a conclusion a single dropped connection does not justify.
     */
    if (status === null && isRepeatable(axiosError.config)) {
      const config = axiosError.config as InternalAxiosRequestConfig;
      const attempts = config.networkAttempts ?? 0;

      if (attempts < NETWORK_RETRIES) {
        config.networkAttempts = attempts + 1;

        return new Promise((resolve) => setTimeout(resolve, NETWORK_RETRY_DELAY_MS)).then(() =>
          api.request(config),
        );
      }
    }

    const body = axiosError.response?.data;
    const isAuthError = status === 401 || status === 419;

    if (isAuthError) {
      onUnauthenticated();
    }

    const message =
      body?.message ??
      (status === null
        ? 'The server could not be reached. Check your connection and try again.'
        : `Request failed with status ${String(status)}.`);

    return Promise.reject(
      new ApiError(
        message,
        status,
        body?.errors ?? {},
        isAuthError,
        typeof body === 'object' && body !== null ? (body as Record<string, unknown>) : null,
      ),
    );
  },
);

/**
 * Prime the CSRF cookie before the first stateful (non-GET) request.
 *
 * Deliberately on the bare axios export rather than on `api`: the endpoint is outside the API
 * prefix and returns no body. It does get the same one retry, because a dropped connection here
 * fails the sign-in that follows it, and fetching a cookie twice costs nothing.
 */
export async function ensureCsrfCookie(): Promise<void> {
  try {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
  } catch (error) {
    if (axios.isAxiosError(error) && error.response === undefined) {
      await new Promise((resolve) => setTimeout(resolve, NETWORK_RETRY_DELAY_MS));
      await axios.get('/sanctum/csrf-cookie', { withCredentials: true });

      return;
    }

    throw error;
  }
}
