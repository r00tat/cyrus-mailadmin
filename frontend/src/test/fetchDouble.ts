import { vi } from 'vitest';

/**
 * A `fetch` double that answers from a scripted table and records what it was
 * asked.
 *
 * A hand-rolled double rather than a mock server (`msw`): the frontend talks to
 * exactly five endpoints, and the properties under test — how many refreshes
 * ten parallel `401`s produce, whether the retry carries the *new* token — are
 * about the sequence of calls, which is easier to assert on a list than to
 * observe through a server.
 */

export interface RecordedCall {
  readonly url: string;
  readonly method: string;
  readonly headers: Headers;
  readonly body: unknown;
}

export type Responder = (call: RecordedCall) => Response | Promise<Response>;

export interface FetchDouble {
  readonly fetch: typeof globalThis.fetch;
  readonly calls: RecordedCall[];
  /** Every call to a path, in order. */
  callsTo(path: string): RecordedCall[];
}

export function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

export function problemResponse(
  status: number,
  code: string,
  extra: Record<string, unknown> = {},
  headers: Record<string, string> = {},
): Response {
  return new Response(
    JSON.stringify({ type: 'about:blank', title: 'Test', status, code, ...extra }),
    {
      status,
      headers: { 'Content-Type': 'application/problem+json', 'X-Request-Id': 'req-test', ...headers },
    },
  );
}

export function emptyResponse(status = 204): Response {
  return new Response(null, { status });
}

export function createFetchDouble(responder: Responder): FetchDouble {
  const calls: RecordedCall[] = [];

  const fetchImpl = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const rawBody = init?.body;

    const call: RecordedCall = {
      url,
      method: (init?.method ?? 'GET').toUpperCase(),
      headers: new Headers(init?.headers ?? {}),
      body: typeof rawBody === 'string' && rawBody !== '' ? JSON.parse(rawBody) : undefined,
    };

    calls.push(call);

    return responder(call);
  });

  return {
    fetch: fetchImpl,
    calls,
    callsTo: (path: string) => calls.filter((call) => new URL(call.url, 'http://test.invalid').pathname === path),
  };
}
