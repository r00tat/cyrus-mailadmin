import { describe, expect, it } from 'vitest';

import { createMemoryTokenStore } from '../auth/tokenStore';
import { createFetchDouble, emptyResponse, jsonResponse, problemResponse, type Responder } from '../test/fetchDouble';

import { ApiClient } from './client';
import { ifMatch, isVersionConflict } from './etag';
import { ApiProblem } from './problem';

const ETAG = 'W/"9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"';

function setUp(responder: Responder) {
  const tokenStore = createMemoryTokenStore();

  tokenStore.write('access-1', 3600);

  const double = createFetchDouble(responder);
  const client = new ApiClient({ baseUrl: '/api/v1', tokenStore, fetch: double.fetch });

  return { client, double };
}

describe('ETag and If-Match (U-19)', () => {
  it('hands back the ETag together with the body', async () => {
    const { client } = setUp(() => jsonResponse(200, { name: 'example.test' }, { ETag: ETAG }));

    const result = await client.getVersioned('/domains/{domain}', { pathParams: { domain: 'example.test' } });

    expect(result.etag).toBe(ETAG);
    expect(result.data).toEqual({ name: 'example.test' });
  });

  it('reports a missing ETag as null rather than inventing one', async () => {
    const { client } = setUp(() => jsonResponse(200, { name: 'example.test' }));

    const result = await client.getVersioned('/domains/{domain}', { pathParams: { domain: 'example.test' } });

    expect(result.etag).toBeNull();
  });

  it('sends the tag verbatim as If-Match on a conditional write', async () => {
    const { client, double } = setUp(() => emptyResponse());

    await client.delete('/domains/{domain}', {
      pathParams: { domain: 'example.test' },
      headers: ifMatch(ETAG),
    });

    expect(double.calls[0]?.method).toBe('DELETE');
    expect(double.calls[0]?.headers.get('If-Match')).toBe(ETAG);
  });

  it('carries the new ETag of a PATCH answer', async () => {
    const { client, double } = setUp(() => jsonResponse(200, { name: 'example.test' }, { ETag: 'W/"new"' }));

    const result = await client.requestVersioned('patch', '/domains/{domain}', {
      pathParams: { domain: 'example.test' },
      body: { max_accounts: 10 },
      headers: ifMatch(ETAG),
    });

    expect(result.etag).toBe('W/"new"');
    expect(double.calls[0]?.method).toBe('PATCH');
    expect(double.calls[0]?.body).toEqual({ max_accounts: 10 });
  });

  it('refuses to build If-Match without a tag — the view loaded the object the wrong way', () => {
    expect(() => ifMatch(null)).toThrow(/getVersioned/);
    expect(() => ifMatch(undefined)).toThrow(/getVersioned/);
    expect(() => ifMatch('  ')).toThrow(/getVersioned/);
  });

  it('recognises a 412 as a version conflict and nothing else as one', async () => {
    const { client } = setUp(() => problemResponse(412, 'request.precondition_failed'));

    const error = await client
      .delete('/domains/{domain}', { pathParams: { domain: 'example.test' }, headers: ifMatch(ETAG) })
      .catch((cause: unknown) => cause);

    expect(error).toBeInstanceOf(ApiProblem);
    expect(isVersionConflict(error)).toBe(true);
    expect(isVersionConflict(new ApiProblem({ status: 428, code: 'request.precondition_required', errors: {} }))).toBe(false);
    expect(isVersionConflict(new Error('x'))).toBe(false);
  });
});
