import { createTokenStore } from '../../auth/tokenStore';
import { jsonResponse, problemResponse, type RecordedCall } from '../../test/fetchDouble';
import { pathOf, renderApp, type RenderedApp } from '../../test/renderApp';

/**
 * Test support for the domains-admins-lists views: a signed-in application
 * with a scripted API behind it. Not a test file itself; imported by the tests
 * in `pages/domains`, `pages/admins` and `pages/lists`.
 */

const BASE_SESSION = {
  domains: [] as string[],
  expires_at: '2026-01-01T13:00:00Z',
};

/** A superuser as `RolePermissions::superuser()` grants it, minus what these views never ask for. */
export const SUPERUSER = {
  ...BASE_SESSION,
  username: 'root',
  role: 'superuser' as const,
  permissions: [
    'session.read',
    'session.write',
    'profile.password.write',
    'domain.read',
    'domain.write',
    'domain.delete',
    'account.read',
    'address.read',
    'admin.read',
    'admin.write',
    'admin.delete',
    'list.read',
    'list.write',
    'list.delete',
    'maintenance.read',
    'audit.read',
  ],
};

/** A domain master as `RolePermissions::domainMaster()` grants it. */
export const DOMAIN_MASTER = {
  ...BASE_SESSION,
  username: 'master',
  role: 'domainmaster' as const,
  domains: ['example.test'],
  permissions: [
    'session.read',
    'session.write',
    'profile.password.write',
    'domain.read',
    'account.read',
    'account.write',
    'account.delete',
    'account.password.write',
    'address.read',
    'address.write',
    'address.delete',
  ],
};

export type TestSession = typeof SUPERUSER | typeof DOMAIN_MASTER;

/** Answers a call, or `undefined` to fall through to `404 request.not_found`. */
export type Handler = (call: RecordedCall, path: string) => Response | undefined;

/**
 * Mounts the application signed in as `session` at `initialEntry`. API paths
 * are handed to `handler` without the `/api/v1` prefix.
 */
export function signedIn(session: TestSession, initialEntry: string, handler: Handler): RenderedApp {
  // A stored token, as after a reload: the guard asks /auth/me and renders.
  createTokenStore().write('access-1', 3600);

  return renderApp(
    (call) => {
      const path = pathOf(call.url).replace(/^\/api\/v1/, '');

      if (path === '/auth/me') {
        return jsonResponse(200, session);
      }

      return handler(call, path) ?? problemResponse(404, 'request.not_found');
    },
    { initialEntries: [initialEntry] },
  );
}

/** The query of a recorded call, as a plain object. */
export function queryOf(call: RecordedCall): Record<string, string> {
  return Object.fromEntries(new URL(call.url, 'http://test.invalid').searchParams);
}

export const PAGE_META = { page: 1, per_page: 50, total: 1 };
