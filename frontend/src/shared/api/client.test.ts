import { afterEach, describe, expect, it } from 'vitest';
import { AxiosError, type AxiosRequestConfig, type AxiosResponse } from 'axios';
import { api, ApiError } from './client';

/*
|--------------------------------------------------------------------------
| The API client
|--------------------------------------------------------------------------
|
| Two rules, and the difference between them is the point: a request that came back with a status
| got an answer, and answers are never retried. A request that came back with nothing may simply
| have been unlucky, and telling somebody the server is unreachable on that evidence is a
| conclusion the evidence does not support.
|
| Driven through a replacement adapter rather than a mocking library, so the interceptors under
| test are the real ones and nothing is stubbed between them and the caller.
|
*/

const realAdapter = api.defaults.adapter;

afterEach(() => {
  if (realAdapter === undefined) {
    delete api.defaults.adapter;
  } else {
    api.defaults.adapter = realAdapter;
  }
});

type Handler = (config: AxiosRequestConfig) => Promise<AxiosResponse> | AxiosResponse;

/** Replace the transport and count how many times it is asked. */
function transport(handler: Handler): { attempts: () => number } {
  let attempts = 0;

  api.defaults.adapter = async (config) => {
    attempts += 1;

    return handler(config);
  };

  return { attempts: () => attempts };
}

/**
 * A connection that failed with nothing at all — no status, no body.
 *
 * Built as a real `AxiosError` carrying its config, because that is what the transport layer
 * hands the interceptors, and the retry depends on being able to see which request failed.
 */
function connectionFailed(config: AxiosRequestConfig): Promise<never> {
  return Promise.reject(
    new AxiosError('socket hang up', AxiosError.ERR_NETWORK, config as never, {}, undefined),
  );
}

/**
 * A response, settled the way a transport settles one.
 *
 * An adapter is what decides whether a status is an error, so a non-2xx is rejected here rather
 * than resolved — otherwise the test would be asserting against a path no real request takes.
 */
function answered(
  status: number,
  data: unknown,
  config: AxiosRequestConfig,
): Promise<AxiosResponse> {
  const response: AxiosResponse = {
    status,
    statusText: '',
    data,
    headers: {},
    config: config as AxiosResponse['config'],
  };

  return status >= 200 && status < 300
    ? Promise.resolve(response)
    : Promise.reject(
        new AxiosError(
          `Request failed with status code ${String(status)}`,
          AxiosError.ERR_BAD_RESPONSE,
          config as never,
          {},
          response,
        ),
      );
}

describe('a request that gets no response', () => {
  it('is sent again once before giving up', async () => {
    const counter = transport((config) =>
      // First attempt: the connection fails. Second: an answer.
      counter.attempts() === 1 ? connectionFailed(config) : answered(200, { data: [] }, config),
    );

    const response = await api.get('/clients');

    expect(counter.attempts()).toBe(2);
    expect(response.status).toBe(200);
  });

  it('gives up after one retry rather than hammering a server that is down', async () => {
    const counter = transport((config) => connectionFailed(config));

    await expect(api.get('/clients')).rejects.toBeInstanceOf(ApiError);

    // Two in total. More would turn a real outage into a page that hangs.
    expect(counter.attempts()).toBe(2);
  });

  it('does not repeat a write nothing has said is safe to repeat', async () => {
    const counter = transport((config) => connectionFailed(config));

    await expect(api.post('/clients', { name: 'Noon' })).rejects.toBeInstanceOf(ApiError);

    /*
     * A request that failed without a response may still have been received and acted on. Sending
     * it again would create a second client, and a duplicate record is worse than an error message.
     */
    expect(counter.attempts()).toBe(1);
  });

  it('repeats a write that opted in', async () => {
    const counter = transport((config) =>
      counter.attempts() === 1 ? connectionFailed(config) : answered(200, { data: {} }, config),
    );

    // Signing in twice signs you in once, which is why this one is allowed to be repeated.
    const response = await api.post('/auth/login', {}, { retryOnNetworkError: true });

    expect(counter.attempts()).toBe(2);
    expect(response.status).toBe(200);
  });

  it('says the server could not be reached, rather than inventing a status', async () => {
    transport((config) => connectionFailed(config));

    const error = (await api.get('/clients').catch((caught: unknown) => caught)) as ApiError;

    expect(error.status).toBeNull();
    expect(error.message).toContain('could not be reached');
  });
});

describe('a request that gets an answer', () => {
  it('is never sent again, whatever the status', async () => {
    const counter = transport((config) => answered(500, { message: 'Something broke.' }, config));

    const error = (await api.get('/clients').catch((caught: unknown) => caught)) as ApiError;

    /*
     * A 500 is an answer. Retrying it would double the load on a server already failing, and the
     * reader would wait twice as long for the same message.
     */
    expect(counter.attempts()).toBe(1);
    expect(error.status).toBe(500);
    expect(error.message).toBe('Something broke.');
  });

  it('keeps the validation messages the server sent, per field', async () => {
    transport((config) =>
      answered(
        422,
        {
          message: 'The given data was invalid.',
          errors: { name: ['A client with that name already exists.'] },
        },
        config,
      ),
    );

    const error = (await api.post('/clients', {}).catch((caught: unknown) => caught)) as ApiError;

    // The server decides what is wrong with a field; the interface only places the message.
    expect(error.status).toBe(422);
    expect(error.fieldError('name')).toBe('A client with that name already exists.');
  });
});
