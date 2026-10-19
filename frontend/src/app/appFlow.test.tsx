import { screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ACCESS_TOKEN_STORAGE_KEY } from '../auth/tokenStore';
import { emptyResponse, jsonResponse, problemResponse } from '../test/fetchDouble';
import { pathOf, renderApp } from '../test/renderApp';

/**
 * The flow the definition of done names: sign in, navigate, sign out — and
 * afterwards no token is stored.
 *
 * It runs in jsdom against a scripted `fetch` rather than in a browser against
 * a live server (U-75). What it does exercise is the whole application: the
 * real route table, the real providers, the real client, the real token store.
 */

const SESSION = {
  username: 'testadmin',
  role: 'domainmaster' as const,
  permissions: ['session.read', 'session.write', 'domain.read'],
  domains: ['example.test'],
  expires_at: '2026-01-01T13:00:00Z',
};

function scriptedApi(options: { signedIn?: boolean } = {}) {
  let signedIn = options.signedIn ?? false;

  return (call: { url: string; method: string }) => {
    const path = pathOf(call.url);

    if (path === '/api/v1/auth/token') {
      signedIn = true;

      return jsonResponse(200, { access_token: 'access-1', token_type: 'Bearer', expires_in: 3600 });
    }

    if (path === '/api/v1/auth/logout') {
      signedIn = false;

      return emptyResponse();
    }

    if (path === '/api/v1/auth/me') {
      return signedIn ? jsonResponse(200, SESSION) : problemResponse(401, 'request.unauthenticated');
    }

    return problemResponse(404, 'request.not_found');
  };
}

describe('the application, end to end in jsdom', () => {
  it('signs in, shows the session, signs out, and leaves no token behind', async () => {
    const { user, tokenStore } = renderApp(scriptedApi());

    // No token: the guard sends the user to the sign-in form.
    const submit = await screen.findByRole('button', { name: 'Anmelden' });

    await user.type(screen.getByLabelText(/Benutzername/), 'testadmin');
    await user.type(screen.getByLabelText(/Passwort/), 'correct horse');
    await user.click(submit);

    // Signed in: the overview renders what GET /auth/me answered.
    expect(await screen.findByText('Angemeldet als testadmin.')).toBeInTheDocument();
    expect(screen.getByText('Domainverwaltung')).toBeInTheDocument();
    expect(screen.getByText('example.test')).toBeInTheDocument();
    expect(tokenStore.read()?.token).toBe('access-1');
    expect(window.localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY)).not.toBeNull();

    // Navigate: the drawer entry is a real link into the router.
    await user.click(screen.getByRole('link', { name: 'Übersicht' }));

    expect(await screen.findByRole('heading', { name: 'Übersicht' })).toBeInTheDocument();

    // Sign out.
    await user.click(screen.getByRole('button', { name: 'Konto' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Abmelden' }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Anmelden' })).toBeInTheDocument();
    });

    // The point of the whole test.
    expect(tokenStore.read()).toBeNull();
    expect(window.localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('clears the token even when the sign-out call fails', async () => {
    const { user, tokenStore } = renderApp((call) => {
      const path = pathOf(call.url);

      if (path === '/api/v1/auth/token') {
        return jsonResponse(200, { access_token: 'access-1', token_type: 'Bearer', expires_in: 3600 });
      }

      if (path === '/api/v1/auth/logout') {
        throw new TypeError('Failed to fetch');
      }

      if (path === '/api/v1/auth/me') {
        return jsonResponse(200, SESSION);
      }

      return problemResponse(404, 'request.not_found');
    });

    await user.type(await screen.findByLabelText(/Benutzername/), 'testadmin');
    await user.type(screen.getByLabelText(/Passwort/), 'correct horse');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    await screen.findByText('Angemeldet als testadmin.');

    await user.click(screen.getByRole('button', { name: 'Konto' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Abmelden' }));

    await waitFor(() => {
      expect(tokenStore.read()).toBeNull();
    });
  });

  it('sends a signed-out visitor to the sign-in form, whatever path they asked for', async () => {
    renderApp(scriptedApi(), { initialEntries: ['/nothing-here'] });

    expect(await screen.findByRole('button', { name: 'Anmelden' })).toBeInTheDocument();
  });

  it('renders the client-side 404 for a path the router does not know', async () => {
    // The web server hands every unknown path to the shell so that deep links
    // work (architecture 11.1), so this page — not Apache — is what a mistyped
    // URL produces.
    window.localStorage.setItem(
      ACCESS_TOKEN_STORAGE_KEY,
      JSON.stringify({ token: 'access-1', expiresAt: Date.now() + 3_600_000 }),
    );

    renderApp(scriptedApi({ signedIn: true }), { initialEntries: ['/nothing-here'] });

    expect(await screen.findByRole('heading', { name: 'Seite nicht gefunden' })).toBeInTheDocument();
  });

  it('keeps a stored token across a reload instead of asking again', async () => {
    window.localStorage.setItem(
      ACCESS_TOKEN_STORAGE_KEY,
      JSON.stringify({ token: 'access-1', expiresAt: Date.now() + 3_600_000 }),
    );

    renderApp(scriptedApi({ signedIn: true }));

    expect(await screen.findByText('Angemeldet als testadmin.')).toBeInTheDocument();
  });

  it('shows the sign-in form again when the stored token is no longer accepted', async () => {
    window.localStorage.setItem(
      ACCESS_TOKEN_STORAGE_KEY,
      JSON.stringify({ token: 'stale', expiresAt: Date.now() + 3_600_000 }),
    );

    const { tokenStore } = renderApp((call) => {
      const path = pathOf(call.url);

      if (path === '/api/v1/auth/refresh') {
        return problemResponse(401, 'auth.refresh_invalid');
      }

      return problemResponse(401, 'request.unauthenticated');
    });

    expect(await screen.findByRole('button', { name: 'Anmelden' })).toBeInTheDocument();
    expect(tokenStore.read()).toBeNull();
  });
});
