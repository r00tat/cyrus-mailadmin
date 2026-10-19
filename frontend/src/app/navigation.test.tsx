import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ACCESS_TOKEN_STORAGE_KEY } from '../auth/tokenStore';
import { jsonResponse, problemResponse } from '../test/fetchDouble';
import { pathOf, renderApp } from '../test/renderApp';

import { NAVIGATION, isActive, visibleNavigation } from './navigation';

/**
 * Routing and navigation for the area views (U-130), through the whole
 * application: the real route table, the real layout, a scripted `/auth/me`.
 */

const SUPERUSER = {
  username: 'root',
  role: 'superuser' as const,
  permissions: [
    'session.read',
    'session.write',
    'domain.read',
    'account.read',
    'address.read',
    'list.read',
    'admin.read',
    'maintenance.read',
    'audit.read',
  ],
  domains: [],
  expires_at: '2026-01-01T13:00:00Z',
};

// The domain master's permissions as RolePermissions::domainMaster() grants them.
const DOMAIN_MASTER = {
  username: 'master',
  role: 'domainmaster' as const,
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

function signedInAs(session: typeof SUPERUSER | typeof DOMAIN_MASTER, initialEntry = '/') {
  // A stored token, as after a reload: the guard asks /auth/me and renders.
  window.localStorage.setItem(
    ACCESS_TOKEN_STORAGE_KEY,
    JSON.stringify({ token: 'access-1', expiresAt: Date.now() + 3_600_000 }),
  );

  return renderApp(
    (call) =>
      pathOf(call.url) === '/api/v1/auth/me'
        ? jsonResponse(200, session)
        : problemResponse(404, 'request.not_found'),
    { initialEntries: [initialEntry] },
  );
}

async function navigation(): Promise<HTMLElement> {
  return screen.findByRole('navigation', { name: 'Hauptnavigation' });
}

const AREA_PAGES = [
  { link: 'Domains', path: '/domains', heading: 'Domains' },
  { link: 'Konten', path: '/accounts', heading: 'Konten' },
  { link: 'Adressen', path: '/addresses', heading: 'Adressen' },
  { link: 'Mailinglisten', path: '/lists', heading: 'Mailinglisten' },
  { link: 'Administratoren', path: '/admins', heading: 'Administratoren' },
  { link: 'Reparaturen', path: '/maintenance/repairs', heading: 'Reparaturwarteschlange' },
  { link: 'Abgleich', path: '/maintenance/reconciliation', heading: 'Abgleich' },
  { link: 'Systemzustand', path: '/maintenance/health', heading: 'Systemzustand' },
  { link: 'Revisionsprotokoll', path: '/audit', heading: 'Revisionsprotokoll' },
] as const;

describe('area navigation', () => {
  it('shows a superuser every area, grouped, and every link reaches its page', async () => {
    const { user } = signedInAs(SUPERUSER);
    const nav = await navigation();

    expect(within(nav).getByText('Verwaltung')).toBeInTheDocument();
    expect(within(nav).getByText('Betrieb')).toBeInTheDocument();

    for (const page of AREA_PAGES) {
      await user.click(within(nav).getByRole('link', { name: page.link }));

      expect(await screen.findByRole('heading', { level: 1, name: page.heading })).toBeInTheDocument();
      expect(within(nav).getByRole('link', { name: page.link })).toHaveAttribute('aria-current', 'page');
      expect(document.title).toBe(`${page.heading} — mailadmin`);
    }
  });

  it('shows a domain master only what the role may use', async () => {
    signedInAs(DOMAIN_MASTER);
    const nav = await navigation();

    const links = within(nav).getAllByRole('link').map((link) => link.textContent);

    expect(links).toEqual(['Übersicht', 'Domains', 'Konten', 'Adressen']);
    expect(within(nav).queryByText('Betrieb')).toBeNull();
  });

  it('leads a domain master to the own password through the account menu', async () => {
    const { user } = signedInAs(DOMAIN_MASTER);

    await navigation();
    await user.click(screen.getByRole('button', { name: 'Konto' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Eigenes Passwort ändern' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'Eigenes Passwort ändern' })).toBeInTheDocument();
  });

  it('offers the own password only to a role that may change it', async () => {
    const { user } = signedInAs(SUPERUSER);

    await navigation();
    await user.click(screen.getByRole('button', { name: 'Konto' }));

    expect(await screen.findByRole('menuitem', { name: 'Abmelden' })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Eigenes Passwort ändern' })).toBeNull();
  });

  it('answers a deep link outside the role with the forbidden state, not with the page', async () => {
    signedInAs(DOMAIN_MASTER, '/admins');

    expect(await screen.findByRole('alert')).toHaveTextContent('Keine Berechtigung');
    expect(screen.queryByRole('heading', { name: 'Administratoren' })).toBeNull();
  });

  it('keeps unknown paths on the client-side 404', async () => {
    signedInAs(SUPERUSER, '/domains-but-not-really');

    expect(await screen.findByRole('heading', { name: 'Seite nicht gefunden' })).toBeInTheDocument();
  });
});

describe('the navigation data', () => {
  it('names a permission for every area entry', () => {
    const areaEntries = NAVIGATION.flatMap((section) => section.entries).filter((entry) => entry.to !== '/');

    expect(areaEntries.every((entry) => entry.permission !== undefined)).toBe(true);
    expect(areaEntries.map((entry) => entry.to).sort()).toEqual(AREA_PAGES.map((page) => page.path).sort());
  });

  it('drops a section with nothing left to show', () => {
    expect(visibleNavigation(() => false).map((section) => section.labelKey)).toEqual([undefined]);
  });

  it('marks an entry active on the views below it, and the overview only on itself', () => {
    const [overview] = NAVIGATION[0]?.entries ?? [];
    const domains = NAVIGATION[1]?.entries[0];

    expect(overview && isActive(overview, '/domains')).toBe(false);
    expect(domains && isActive(domains, '/domains/example.test')).toBe(true);
    expect(domains && isActive(domains, '/domainsx')).toBe(false);
  });
});
