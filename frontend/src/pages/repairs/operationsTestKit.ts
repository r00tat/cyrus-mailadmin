import { createTokenStore } from '../../auth/tokenStore';
import { jsonResponse, problemResponse, type RecordedCall } from '../../test/fetchDouble';
import { pathOf, renderApp, type RenderedApp } from '../../test/renderApp';

/**
 * Sessions and a signed-in `renderApp` for the tests of the operations area
 * (A6, S4). Test support only — imported by the `*.test.tsx` files of
 * `pages/repairs`, `pages/reconciliation`, `pages/health` and `pages/audit`.
 */

const BASE_PERMISSIONS = ['session.read', 'session.write'];

export const SUPERUSER = {
  username: 'root',
  role: 'superuser' as const,
  permissions: [...BASE_PERMISSIONS, 'domain.read', 'maintenance.read', 'maintenance.write', 'audit.read'],
  domains: [],
  expires_at: '2026-01-01T13:00:00Z',
};

/**
 * A session that may look but not act, and not read the audit trail. No role
 * has exactly this today; it exists to show that every write control and the
 * audit link follow the permission, not the role.
 */
export const READ_ONLY = {
  ...SUPERUSER,
  username: 'observer',
  permissions: [...BASE_PERMISSIONS, 'maintenance.read'],
};

/** The domain master's permissions as RolePermissions::domainMaster() grants them. */
export const DOMAIN_MASTER = {
  username: 'master',
  role: 'domainmaster' as const,
  permissions: [
    ...BASE_PERMISSIONS,
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
  domains: ['example.test'],
  expires_at: '2026-01-01T13:00:00Z',
};

export type Session = typeof SUPERUSER | typeof READ_ONLY | typeof DOMAIN_MASTER;

/** Answers one call, or `undefined` to fall through to `404 request.not_found`. */
export type Route = (call: RecordedCall, path: string) => Response | Promise<Response> | undefined;

export function renderSignedIn(session: Session, initialEntry: string, route: Route = () => undefined): RenderedApp {
  // A stored token, as after a reload: the guard asks /auth/me and renders.
  createTokenStore().write('access-1', 3600);

  return renderApp(
    (call) => {
      const path = pathOf(call.url);

      if (path === '/api/v1/auth/me') {
        return jsonResponse(200, session);
      }

      return route(call, path) ?? problemResponse(404, 'request.not_found');
    },
    { initialEntries: [initialEntry] },
  );
}

/** The query string of a recorded call, as a plain object. */
export function queryOf(call: RecordedCall | undefined): Record<string, string> {
  if (call === undefined) {
    return {};
  }

  return Object.fromEntries(new URL(call.url, 'http://test.invalid').searchParams.entries());
}
