import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ACCESS_TOKEN_STORAGE_KEY } from '../auth/tokenStore';
import { jsonResponse, problemResponse, type RecordedCall } from '../test/fetchDouble';
import { pathOf, renderApp } from '../test/renderApp';

/**
 * W2's per-administrator language, through the whole application: read the
 * stored choice, change it, and see it stored (U-90).
 */

const SESSION = {
  username: 'testadmin',
  role: 'domainmaster' as const,
  permissions: ['profile.preferences.read', 'profile.preferences.write', 'session.read', 'session.write'],
  domains: ['example.test', 'example.org'],
  expires_at: '2026-01-01T13:00:00Z',
};

function signedIn(): void {
  window.localStorage.setItem(
    ACCESS_TOKEN_STORAGE_KEY,
    JSON.stringify({ token: 'access-1', expiresAt: Date.now() + 3_600_000 }),
  );
}

function scriptedApi(stored: { locale: 'de' | null }) {
  const puts: unknown[] = [];

  const responder = (call: RecordedCall) => {
    const path = pathOf(call.url);

    if (path === '/api/v1/auth/me') {
      return jsonResponse(200, SESSION);
    }

    if (path === '/api/v1/admins/me/preferences') {
      if (call.method === 'PUT') {
        const body = call.body as { locale: 'de' | null };

        puts.push(body);
        stored.locale = body.locale;
      }

      return jsonResponse(200, stored);
    }

    return problemResponse(404, 'request.not_found');
  };

  return { responder, puts };
}

describe('the language preference', () => {
  it('stores an explicit choice and reports it', async () => {
    signedIn();
    const { responder, puts } = scriptedApi({ locale: null });
    const { user } = renderApp(responder);

    const select = await screen.findByRole('combobox', { name: 'Sprache der Oberfläche' });

    await waitFor(() => {
      expect(select).toHaveTextContent('Wie im Browser eingestellt (Deutsch)');
    });

    await user.click(select);
    await user.click(within(await screen.findByRole('listbox')).getByRole('option', { name: 'Deutsch' }));

    expect(await screen.findByText('Gespeichert.')).toBeInTheDocument();
    expect(puts).toEqual([{ locale: 'de' }]);
    expect(document.documentElement.lang).toBe('de');
  });

  it('says in which time zone times are shown', async () => {
    signedIn();
    renderApp(scriptedApi({ locale: 'de' }).responder);

    expect(await screen.findByText(/Zeiten erscheinen in der Zeitzone dieses Browsers/)).toBeInTheDocument();
  });

  it('counts the assigned domains with the right plural form', async () => {
    signedIn();
    renderApp(scriptedApi({ locale: null }).responder);

    expect(await screen.findByText('2 Domains zugewiesen.')).toBeInTheDocument();
  });

  it('is not offered to a session without the permission', async () => {
    signedIn();
    const calls: string[] = [];

    renderApp((call) => {
      calls.push(pathOf(call.url));

      return pathOf(call.url) === '/api/v1/auth/me'
        ? jsonResponse(200, { ...SESSION, permissions: ['session.read'] })
        : problemResponse(404, 'request.not_found');
    });

    await screen.findByText('Angemeldet als testadmin.');

    expect(screen.queryByRole('combobox', { name: 'Sprache der Oberfläche' })).toBeNull();
    expect(calls).not.toContain('/api/v1/admins/me/preferences');
  });
});
