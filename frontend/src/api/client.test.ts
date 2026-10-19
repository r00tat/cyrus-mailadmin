import { describe, expect, it, vi } from 'vitest';

import { createMemoryTokenStore } from '../auth/tokenStore';
import {
  createFetchDouble,
  emptyResponse,
  jsonResponse,
  problemResponse,
  type Responder,
} from '../test/fetchDouble';

import { ApiClient, PENDING_CONFIGURATION_RUN, hasPendingConfigurationRun } from './client';
import { ApiProblem } from './problem';

const BASE = '/api/v1';

function setUp(responder: Responder, token: string | null = 'access-1') {
  const tokenStore = createMemoryTokenStore();

  if (token !== null) {
    tokenStore.write(token, 3600);
  }

  const onSessionLost = vi.fn();
  const double = createFetchDouble(responder);

  const client = new ApiClient({
    baseUrl: BASE,
    tokenStore,
    onSessionLost,
    fetch: double.fetch,
  });

  return { client, tokenStore, onSessionLost, double };
}

describe('ApiClient — the basics', () => {
  it('sends the stored access token as a bearer token', async () => {
    const { client, double } = setUp(() => jsonResponse(200, { username: 'admin' }));

    await client.get('/auth/me');

    expect(double.calls[0]?.headers.get('Authorization')).toBe('Bearer access-1');
    expect(double.calls[0]?.url).toBe('/api/v1/auth/me');
  });

  it('sends no Authorization header when there is no token', async () => {
    const { client, double } = setUp(() => jsonResponse(200, {}), null);

    await client.get('/auth/me');

    expect(double.calls[0]?.headers.has('Authorization')).toBe(false);
  });

  it('carries the refresh cookie by staying same-origin, never cross-origin', () => {
    expect(
      () =>
        new ApiClient({
          baseUrl: 'https://elsewhere.invalid/api/v1',
          tokenStore: createMemoryTokenStore(),
        }),
    ).toThrow(/same-origin/);
  });

  it('resolves a 204 to nothing rather than to a parse error', async () => {
    const { client } = setUp(() => emptyResponse());

    await expect(client.post('/auth/logout')).resolves.toBeUndefined();
  });

  it('sends an optional request body when one is given, and none when it is left out', async () => {
    const { client, double } = setUp(() => jsonResponse(200, {}));

    await client.post('/maintenance/repairs/{repair}/resolve', { pathParams: { repair: '7' }, body: { note: 'done' } });
    await client.post('/maintenance/repairs/{repair}/resolve', { pathParams: { repair: '7' } });

    expect(double.calls[0]?.body).toEqual({ note: 'done' });
    expect(double.calls[1]?.body).toBeUndefined();
  });
});

describe('ApiClient — X-Mailadmin-Effect (U-59)', () => {
  it('hands the header back with a versioned exchange, also on a 204', async () => {
    const { client } = setUp(
      () => new Response(null, { status: 204, headers: { 'X-Mailadmin-Effect': PENDING_CONFIGURATION_RUN } }),
    );

    const exchange = await client.requestVersioned('delete', '/lists/{listName}', {
      pathParams: { listName: 'team' },
      headers: { 'If-Match': '"l1"' },
    });

    expect(exchange.effect).toBe(PENDING_CONFIGURATION_RUN);
    expect(hasPendingConfigurationRun(exchange)).toBe(true);
  });

  it('reports no effect when the answer carries none', async () => {
    const { client } = setUp(() => jsonResponse(200, { name: 'example.test' }, { ETag: 'W/"1"' }));

    const exchange = await client.getVersioned('/domains/{domain}', { pathParams: { domain: 'example.test' } });

    expect(exchange.effect).toBeNull();
    expect(hasPendingConfigurationRun(exchange)).toBe(false);
  });
});

describe('ApiClient — problem+json', () => {
  it('maps a problem document onto ApiProblem, request id included', async () => {
    const { client } = setUp(() =>
      problemResponse(422, 'request.validation_failed', {
        detail: 'Body failed validation.',
        errors: { 'body.username': 'required' },
      }),
    );

    const error = await client.post('/auth/token', {
      body: { username: '', password: 'x' },
      withoutRefresh: true,
    }).catch((cause: unknown) => cause);

    expect(error).toBeInstanceOf(ApiProblem);

    const problem = error as ApiProblem;

    expect(problem.status).toBe(422);
    expect(problem.code).toBe('request.validation_failed');
    expect(problem.detail).toBe('Body failed validation.');
    expect(problem.requestId).toBe('req-test');
    expect(problem.errors).toEqual({ 'body.username': 'required' });
  });

  it('reads Retry-After on a 429', async () => {
    const { client } = setUp(() =>
      problemResponse(429, 'request.rate_limited', {}, { 'Retry-After': '90' }),
    );

    const problem = (await client
      .post('/auth/token', { body: { username: 'a', password: 'b' }, withoutRefresh: true })
      .catch((cause: unknown) => cause)) as ApiProblem;

    expect(problem.retryAfterSeconds).toBe(90);
    expect(problem.isTransient).toBe(true);
  });

  it('turns a failed connection into client.network without inventing a contract code', async () => {
    const { client } = setUp(() => {
      throw new TypeError('Failed to fetch');
    });

    const problem = (await client.get('/auth/me').catch((cause: unknown) => cause)) as ApiProblem;

    expect(problem.code).toBe('client.network');
    expect(problem.status).toBe(0);
    expect(problem.isTransient).toBe(true);
  });

  it('does not swallow an abort — a cancelled request is not a failure to report', async () => {
    const controller = new AbortController();
    const { client } = setUp(() => {
      throw new DOMException('The operation was aborted.', 'AbortError');
    });

    controller.abort();

    const error = await client.get('/auth/me', { signal: controller.signal }).catch((cause: unknown) => cause);

    expect(error).toBeInstanceOf(DOMException);
    expect(error).not.toBeInstanceOf(ApiProblem);
  });
});

describe('ApiClient — the 401 dance', () => {
  it('refreshes once and repeats the request with the new token', async () => {
    let meCalls = 0;

    const { client, double, tokenStore } = setUp((call) => {
      const path = new URL(call.url, 'http://test.invalid').pathname;

      if (path === '/api/v1/auth/refresh') {
        return jsonResponse(200, { access_token: 'access-2', token_type: 'Bearer', expires_in: 3600 });
      }

      meCalls += 1;

      return meCalls === 1
        ? problemResponse(401, 'request.unauthenticated')
        : jsonResponse(200, { username: 'admin' });
    });

    await expect(client.get('/auth/me')).resolves.toEqual({ username: 'admin' });

    expect(double.callsTo('/api/v1/auth/refresh')).toHaveLength(1);
    expect(double.callsTo('/api/v1/auth/me')).toHaveLength(2);
    expect(double.callsTo('/api/v1/auth/me')[1]?.headers.get('Authorization')).toBe('Bearer access-2');
    expect(tokenStore.read()?.token).toBe('access-2');
  });

  it('sends {} to /auth/refresh, because a body-less POST is a 422 there', async () => {
    const { client, double } = setUp((call) => {
      const path = new URL(call.url, 'http://test.invalid').pathname;

      return path === '/api/v1/auth/refresh'
        ? jsonResponse(200, { access_token: 'access-2', token_type: 'Bearer', expires_in: 60 })
        : problemResponse(401, 'request.unauthenticated');
    });

    await client.get('/auth/me').catch(() => undefined);

    const refresh = double.callsTo('/api/v1/auth/refresh')[0];

    expect(refresh?.body).toEqual({});
    expect(refresh?.headers.get('Content-Type')).toBe('application/json');
  });

  it('bundles parallel 401s into a single refresh — a second one would look like a replay', async () => {
    let refreshes = 0;
    let released!: () => void;
    const gate = new Promise<void>((resolve) => {
      released = resolve;
    });

    const seen = new Map<string, number>();

    const { client, double } = setUp(async (call) => {
      const path = new URL(call.url, 'http://test.invalid').pathname;

      if (path === '/api/v1/auth/refresh') {
        refreshes += 1;
        // Hold the refresh open so that every caller is genuinely waiting on
        // the same promise rather than arriving after it settled.
        await gate;

        return jsonResponse(200, { access_token: 'access-2', token_type: 'Bearer', expires_in: 3600 });
      }

      const count = (seen.get(path) ?? 0) + 1;

      seen.set(path, count);

      return count === 1
        ? problemResponse(401, 'request.unauthenticated')
        : jsonResponse(200, { ok: path });
    });

    const inFlight = [
      client.get('/auth/me'),
      client.get('/health'),
      client.get('/auth/me'),
      client.get('/health'),
    ];

    // Let all four reach their 401 before the refresh is allowed to finish.
    await Promise.resolve();
    await Promise.resolve();
    released();

    await Promise.all(inFlight.map((promise) => promise.catch(() => undefined)));

    expect(refreshes).toBe(1);
    expect(double.callsTo('/api/v1/auth/refresh')).toHaveLength(1);
  });

  it('gives up after one refresh: a 401 on the retry is not refreshed again', async () => {
    let refreshes = 0;

    const { client, double, tokenStore, onSessionLost } = setUp((call) => {
      const path = new URL(call.url, 'http://test.invalid').pathname;

      if (path === '/api/v1/auth/refresh') {
        refreshes += 1;

        return jsonResponse(200, { access_token: 'access-2', token_type: 'Bearer', expires_in: 3600 });
      }

      return problemResponse(401, 'request.unauthenticated');
    });

    await expect(client.get('/auth/me')).rejects.toBeInstanceOf(ApiProblem);

    expect(refreshes).toBe(1);
    expect(double.callsTo('/api/v1/auth/me')).toHaveLength(2);
    expect(tokenStore.read()).toBeNull();
    expect(onSessionLost).toHaveBeenCalledTimes(1);
  });

  it('clears the token and reports the session lost when the refresh itself fails', async () => {
    const { client, tokenStore, onSessionLost } = setUp((call) => {
      const path = new URL(call.url, 'http://test.invalid').pathname;

      return path === '/api/v1/auth/refresh'
        ? problemResponse(401, 'auth.refresh_invalid')
        : problemResponse(401, 'request.unauthenticated');
    });

    const problem = (await client.get('/auth/me').catch((cause: unknown) => cause)) as ApiProblem;

    // The caller sees the failure of *its* request, not of the refresh.
    expect(problem.status).toBe(401);
    expect(tokenStore.read()).toBeNull();
    expect(onSessionLost).toHaveBeenCalledTimes(1);
  });

  it('reports a lost session once, not once per request that was in flight', async () => {
    const { client, onSessionLost } = setUp(() => problemResponse(401, 'auth.refresh_invalid'));

    await Promise.all([
      client.get('/auth/me').catch(() => undefined),
      client.get('/auth/me').catch(() => undefined),
      client.get('/auth/me').catch(() => undefined),
    ]);

    expect(onSessionLost).toHaveBeenCalledTimes(1);
  });

  it('never refreshes on a failed sign-in — wrong credentials are not an expired session', async () => {
    const { client, double } = setUp(() => problemResponse(401, 'auth.invalid_credentials'), null);

    await client
      .post('/auth/token', {
        body: { username: 'admin', password: 'wrong' },
        withoutRefresh: true,
      })
      .catch(() => undefined);

    expect(double.callsTo('/api/v1/auth/refresh')).toHaveLength(0);
  });
});
