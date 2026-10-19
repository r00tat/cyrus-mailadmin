import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { jsonResponse, problemResponse } from '../test/fetchDouble';
import { pathOf, renderApp } from '../test/renderApp';

/**
 * The sign-in form with good and with bad input.
 *
 * Two properties matter beyond "does it submit": the password never leaves the
 * component in a URL or a log, and the refresh token is requested as a cookie.
 * Both are decisions from E-15, and both are invisible in the rendered output —
 * so they are asserted on the request.
 */
describe('the sign-in form', () => {
  it('does not call the API when a field is empty', async () => {
    const { user, double } = renderApp(() => jsonResponse(200, {}));

    await user.click(await screen.findByRole('button', { name: 'Anmelden' }));

    expect(await screen.findByText('Bitte einen Benutzernamen eingeben.')).toBeInTheDocument();
    expect(screen.getByText('Bitte ein Passwort eingeben.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/auth/token')).toHaveLength(0);
  });

  it('posts the credentials in the body and asks for cookie delivery', async () => {
    const { user, double } = renderApp((call) =>
      pathOf(call.url) === '/api/v1/auth/token'
        ? jsonResponse(200, { access_token: 'a', token_type: 'Bearer', expires_in: 3600 })
        : jsonResponse(200, {
            username: 'admin',
            role: 'superuser',
            permissions: [],
            domains: [],
            expires_at: '2026-01-01T13:00:00Z',
          }),
    );

    await user.type(await screen.findByLabelText(/Benutzername/), 'admin');
    await user.type(screen.getByLabelText(/Passwort/), 'a good password');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    const request = double.callsTo('/api/v1/auth/token')[0];

    expect(request?.method).toBe('POST');
    expect(request?.body).toEqual({
      username: 'admin',
      password: 'a good password',
      // E-15: never `body`. A browser that took the refresh token into
      // JavaScript would hand a script the twelve-hour half of the pair.
      refresh_delivery: 'cookie',
    });

    // Nothing secret in the URL — it would end up in every access log.
    expect(request?.url).not.toContain('a good password');
  });

  it('shows the translated message for auth.invalid_credentials, with the request id', async () => {
    const { user } = renderApp((call) =>
      pathOf(call.url) === '/api/v1/auth/token'
        ? problemResponse(401, 'auth.invalid_credentials', { detail: 'Invalid credentials.' })
        : problemResponse(401, 'request.unauthenticated'),
    );

    await user.type(await screen.findByLabelText(/Benutzername/), 'admin');
    await user.type(screen.getByLabelText(/Passwort/), 'wrong');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    // The German text, not the English `detail` the API sent as a fallback.
    expect(await screen.findByText('Benutzername oder Passwort stimmen nicht.')).toBeInTheDocument();
    expect(screen.queryByText('Invalid credentials.')).not.toBeInTheDocument();
    expect(screen.getByText('Vorgangsnummer: req-test')).toBeInTheDocument();
  });

  it('falls back to the English text when the code is newer than this build', async () => {
    const { user } = renderApp((call) =>
      pathOf(call.url) === '/api/v1/auth/token'
        ? problemResponse(409, 'something.invented_later', { detail: 'A future condition.' })
        : problemResponse(401, 'request.unauthenticated'),
    );

    await user.type(await screen.findByLabelText(/Benutzername/), 'admin');
    await user.type(screen.getByLabelText(/Passwort/), 'secret');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    expect(await screen.findByText('A future condition.')).toBeInTheDocument();
  });

  it('puts a field error from the server on the field it belongs to', async () => {
    const { user } = renderApp((call) =>
      pathOf(call.url) === '/api/v1/auth/token'
        ? problemResponse(422, 'request.validation_failed', {
            errors: { 'body.password': 'maxLength' },
          })
        : problemResponse(401, 'request.unauthenticated'),
    );

    await user.type(await screen.findByLabelText(/Benutzername/), 'admin');
    await user.type(screen.getByLabelText(/Passwort/), 'secret');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    expect(await screen.findByText('Die Eingabe ist zu lang.')).toBeInTheDocument();
  });

  it('tells the user to wait when the rate limiter answers', async () => {
    const { user } = renderApp((call) =>
      pathOf(call.url) === '/api/v1/auth/token'
        ? problemResponse(429, 'request.rate_limited', {}, { 'Retry-After': '60' })
        : problemResponse(401, 'request.unauthenticated'),
    );

    await user.type(await screen.findByLabelText(/Benutzername/), 'admin');
    await user.type(screen.getByLabelText(/Passwort/), 'secret');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    expect(await screen.findByText('Zu viele Versuche. Bitte einen Moment warten.')).toBeInTheDocument();
  });

  it('reports an unreachable server without pretending it was a contract error', async () => {
    const { user } = renderApp(() => {
      throw new TypeError('Failed to fetch');
    });

    await user.type(await screen.findByLabelText(/Benutzername/), 'admin');
    await user.type(screen.getByLabelText(/Passwort/), 'secret');
    await user.click(screen.getByRole('button', { name: 'Anmelden' }));

    expect(
      await screen.findByText('Der Server ist nicht erreichbar. Bitte die Verbindung prüfen.'),
    ).toBeInTheDocument();
  });
});
