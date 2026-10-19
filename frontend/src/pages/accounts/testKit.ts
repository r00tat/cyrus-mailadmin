import { createTokenStore } from '../../auth/tokenStore';
import { jsonResponse, problemResponse, type RecordedCall, type Responder } from '../../test/fetchDouble';
import { pathOf, renderApp, type RenderedApp } from '../../test/renderApp';
import type { components } from '../../api/schema';

/**
 * Shared scaffolding for the page tests of the accounts-addresses stream: the
 * two roles as `/auth/me` reports them, and a responder that answers by
 * method and path. Test-only; nothing in the application imports it.
 */

type Session = components['schemas']['CurrentSession'];

export const SUPERUSER: Session = {
  username: 'root',
  role: 'superuser',
  permissions: [
    'session.read',
    'session.write',
    'domain.read',
    'account.read',
    'account.write',
    'account.delete',
    'account.password.write',
    'address.read',
    'address.write',
    'address.delete',
  ],
  domains: [],
  expires_at: '2026-01-01T13:00:00Z',
};

export const DOMAIN_MASTER: Session = {
  username: 'master',
  role: 'domainmaster',
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
  domains: ['example.test'],
  expires_at: '2026-01-01T13:00:00Z',
};

/** A session that may look but not change anything — to prove what is hidden. */
export const READER: Session = {
  username: 'reader',
  role: 'domainmaster',
  permissions: ['session.read', 'domain.read', 'account.read', 'address.read'],
  domains: ['example.test'],
  expires_at: '2026-01-01T13:00:00Z',
};

export type Route = (call: RecordedCall) => Response | Promise<Response>;

/**
 * `routes` maps `"METHOD /api/v1/path"` to an answer. `/auth/me` answers with
 * the session; anything unrouted is a `404 request.not_found`.
 */
export function responder(session: Session, routes: Readonly<Record<string, Route>>): Responder {
  return (call) => {
    const path = pathOf(call.url);

    if (path === '/api/v1/auth/me') {
      return jsonResponse(200, session);
    }

    const route = routes[`${call.method} ${path}`];

    return route === undefined ? problemResponse(404, 'request.not_found') : route(call);
  };
}

export function signedIn(session: Session, routes: Readonly<Record<string, Route>>, entry: string): RenderedApp {
  createTokenStore().write('access-1', 3600);

  return renderApp(responder(session, routes), { initialEntries: [entry] });
}

export function queryOf(call: RecordedCall): URLSearchParams {
  return new URL(call.url, 'http://test.invalid').searchParams;
}

export const page = (items: unknown[]): { items: unknown[]; meta: { page: number; per_page: number; total: number } } => ({
  items,
  meta: { page: 1, per_page: 50, total: items.length },
});

export const ACCOUNT: components['schemas']['Account'] = {
  id: 'anna.example.test',
  domain: 'example.test',
  address: 'anna@example.test',
  local_part: 'anna',
  naming_scheme: 'dotted',
  resolvable: true,
  services: { imap: true, pop: false, sieve: true, smtp_auth: true },
  password_format: 'bcrypt',
  password_format_current: true,
  password_format_weak: false,
  legacy_prefix: '',
  enabled: true,
  disabled_at: null,
  disabled_by: null,
};

export const NO_FORWARD: components['schemas']['Forwards'] = {
  account: 'anna.example.test',
  configured: false,
  destinations: [],
  keep_copy: true,
  copy_required: false,
  enabled: true,
  editable: true,
  entries: [],
};

export const NO_VACATION: components['schemas']['Vacation'] = {
  account: 'anna.example.test',
  state: 'none',
  message: null,
  subject: null,
  interval_days: null,
  addresses: null,
  script_name: null,
  script: null,
};
