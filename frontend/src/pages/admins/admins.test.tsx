import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { emptyResponse, jsonResponse, problemResponse } from '../../test/fetchDouble';
import { DOMAIN_MASTER, PAGE_META, SUPERUSER, queryOf, signedIn } from '../domains/testkit';

/** A4 — the administrator views, through the whole application. */

const ROOT = {
  username: 'root',
  role: 'superuser',
  all_domains: true,
  domains: [],
  orphaned_domains: [],
  password_format: 'argon2id',
  password_legacy: false,
};

const MASTER = {
  username: 'anna',
  role: 'domainmaster',
  all_domains: false,
  domains: ['example.test', 'gone.test'],
  orphaned_domains: ['gone.test'],
  password_format: 'md5crypt',
  password_legacy: true,
};

const DOMAIN_OPTIONS = { items: [{ name: 'example.test' }, { name: 'other.test' }], meta: { ...PAGE_META, total: 2 } };

function adminApi(options: { patch?: () => Response; remove?: () => Response; post?: () => Response } = {}) {
  return (call: { method: string }, path: string): Response | undefined => {
    if (path === '/admins' && call.method === 'GET') {
      return jsonResponse(200, { items: [ROOT, MASTER], meta: { ...PAGE_META, total: 2 } });
    }

    if (path === '/admins' && call.method === 'POST') {
      return options.post?.();
    }

    if (path === '/domains') {
      return jsonResponse(200, DOMAIN_OPTIONS);
    }

    if (path === '/admins/anna') {
      if (call.method === 'GET') {
        return jsonResponse(200, MASTER, { ETag: '"a1"' });
      }

      return call.method === 'PATCH' ? options.patch?.() : options.remove?.();
    }

    if (path === '/admins/root' && call.method === 'GET') {
      return jsonResponse(200, ROOT, { ETag: '"r1"' });
    }

    return undefined;
  };
}

describe('the administrator list', () => {
  it('lists each administrator with role, scope and hash format', async () => {
    const { double } = signedIn(SUPERUSER, '/admins', adminApi());

    expect(await screen.findByText('anna')).toBeInTheDocument();

    const row = screen.getByText('anna').closest('tr')!;

    expect(within(row).getByText('Domainverwaltung')).toBeInTheDocument();
    expect(within(row).getByText('MD5-crypt')).toBeInTheDocument();
    expect(within(row).getByText('Altverfahren')).toBeInTheDocument();
    expect(within(row).getByText('gone.test (verwaist)')).toBeInTheDocument();
    expect(within(screen.getByText('root').closest('tr')!).getByText('Alle Domains')).toBeInTheDocument();

    expect(queryOf(double.callsTo('/api/v1/admins')[0]!)).toMatchObject({ sort: 'username' });
  });

  it('offers creating and the own password to a superuser', async () => {
    signedIn(SUPERUSER, '/admins', adminApi());

    expect(await screen.findByRole('button', { name: 'Administrator anlegen' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Eigenes Passwort ändern' })).toHaveAttribute('href', '/admins/me/password');
  });

  it('is closed to a domain master', async () => {
    signedIn(DOMAIN_MASTER, '/admins', adminApi());

    expect(await screen.findByText('Keine Berechtigung')).toBeInTheDocument();
    expect(screen.queryByText('anna')).not.toBeInTheDocument();
  });

  it('creates a domain administrator with the domains it chose', async () => {
    const { user, double } = signedIn(SUPERUSER, '/admins', adminApi({
      post: () => jsonResponse(201, { ...MASTER, username: 'bert', domains: ['example.test'], orphaned_domains: [] }),
    }));

    await user.click(await screen.findByRole('button', { name: 'Administrator anlegen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: /Benutzername/ }), 'bert');
    await user.type(within(dialog).getByLabelText(/Passwort/), 'correct horse battery');
    await user.type(within(dialog).getByRole('combobox', { name: 'Domains' }), 'example.test{Enter}');
    await user.click(within(dialog).getByRole('button', { name: 'Anlegen' }));

    await waitFor(() => expect(double.callsTo('/api/v1/admins').filter((call) => call.method === 'POST')).toHaveLength(1));

    const [post] = double.callsTo('/api/v1/admins').filter((call) => call.method === 'POST');

    expect(post!.body).toEqual({
      username: 'bert',
      password: 'correct horse battery',
      role: 'domainmaster',
      domains: ['example.test'],
    });
  });

  it('keeps a typed domain that was not confirmed with Enter when the form is sent', async () => {
    const { user, double } = signedIn(SUPERUSER, '/admins', adminApi({
      post: () => jsonResponse(201, { ...MASTER, username: 'bert', domains: ['typed.test'], orphaned_domains: [] }),
    }));

    await user.click(await screen.findByRole('button', { name: 'Administrator anlegen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: /Benutzername/ }), 'bert');
    await user.type(within(dialog).getByLabelText(/Passwort/), 'correct horse battery');
    await user.type(within(dialog).getByRole('combobox', { name: 'Domains' }), 'typed.test');
    await user.click(within(dialog).getByRole('button', { name: 'Anlegen' }));

    await waitFor(() => expect(double.callsTo('/api/v1/admins').filter((call) => call.method === 'POST')).toHaveLength(1));

    const [post] = double.callsTo('/api/v1/admins').filter((call) => call.method === 'POST');

    expect(post!.body).toMatchObject({ domains: ['typed.test'] });
  });

  it('shows a refused creation through the catalogue', async () => {
    const { user } = signedIn(SUPERUSER, '/admins', adminApi({
      post: () => problemResponse(409, 'admin.username_taken', { errors: { 'body.username': 'pattern' } }),
    }));

    await user.click(await screen.findByRole('button', { name: 'Administrator anlegen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: /Benutzername/ }), 'root');
    await user.type(within(dialog).getByLabelText(/Passwort/), 'correct horse battery');
    await user.click(within(dialog).getByRole('button', { name: 'Anlegen' }));

    expect(await within(dialog).findByText('Dieser Benutzername ist bereits vergeben.')).toBeInTheDocument();
  });
});

describe('one administrator', () => {
  it('shows orphaned assignments and the legacy hash', async () => {
    signedIn(SUPERUSER, '/admins/anna', adminApi());

    expect(await screen.findByRole('heading', { level: 1, name: 'anna' })).toBeInTheDocument();
    expect(await screen.findByText('Eine Zuordnung ist verwaist: gone.test')).toBeInTheDocument();
    expect(screen.getByText(/wird bei der nächsten Anmeldung auf Argon2id umgestellt/)).toBeInTheDocument();
  });

  it('sends a changed role with its domains, and the version it showed', async () => {
    const { user, double } = signedIn(SUPERUSER, '/admins/anna', adminApi({
      patch: () => jsonResponse(200, { ...ROOT, username: 'anna' }, { ETag: '"a2"' }),
    }));

    await user.click(await screen.findByRole('combobox', { name: 'Rolle' }));
    await user.click(await screen.findByRole('option', { name: 'Systemverwaltung' }));
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    await waitFor(() =>
      expect(double.callsTo('/api/v1/admins/anna').filter((call) => call.method === 'PATCH')).toHaveLength(1),
    );

    const [patch] = double.callsTo('/api/v1/admins/anna').filter((call) => call.method === 'PATCH');

    expect(patch!.body).toEqual({ role: 'superuser', domains: [] });
    expect(patch!.headers.get('If-Match')).toBe('"a1"');
  });

  it('shows an assignment to a vanished domain on the domain field', async () => {
    const { user } = signedIn(SUPERUSER, '/admins/anna', adminApi({
      patch: () => problemResponse(422, 'admin.unknown_domain', { errors: { 'body.domains.1': 'enum' } }),
    }));

    await user.type(await screen.findByLabelText('Neues Passwort'), 'correct horse battery');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText('Mindestens eine der zugewiesenen Domains existiert nicht.')).toBeInTheDocument();
    expect(screen.getByText('Unzulässiger Wert.')).toBeInTheDocument();
  });

  it('deletes only after the name is typed', async () => {
    const { user, double } = signedIn(SUPERUSER, '/admins/anna', adminApi({ remove: () => emptyResponse(204) }));

    await user.click(await screen.findByRole('button', { name: 'Löschen' }));

    const dialog = await screen.findByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Löschen' });

    expect(confirm).toBeDisabled();

    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'anna');
    await user.click(confirm);

    expect(await screen.findByRole('heading', { level: 1, name: 'Administratoren' })).toBeInTheDocument();

    const [remove] = double.callsTo('/api/v1/admins/anna').filter((call) => call.method === 'DELETE');

    expect(remove!.headers.get('If-Match')).toBe('"a1"');
  });

  it('does not offer deleting oneself', async () => {
    signedIn(SUPERUSER, '/admins/root', adminApi());

    expect(await screen.findByRole('heading', { level: 1, name: 'root' })).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Löschen' })).toBeDisabled();
  });
});

describe('the own password', () => {
  it('is open to a domain master and catches a mistyped repetition', async () => {
    const { user, double } = signedIn(DOMAIN_MASTER, '/admins/me/password', () => undefined);

    await user.type(await screen.findByLabelText(/Aktuelles Passwort/), 'old password!');
    await user.type(screen.getByLabelText(/^Neues Passwort \*/), 'new password 123');
    await user.type(screen.getByLabelText(/Neues Passwort wiederholen/), 'new password 124');
    await user.click(screen.getByRole('button', { name: 'Passwort ändern' }));

    expect(await screen.findByText('Die beiden Eingaben stimmen nicht überein.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/admins/me/password')).toHaveLength(0);
  });

  it('shows a wrong current password through the catalogue', async () => {
    const { user } = signedIn(DOMAIN_MASTER, '/admins/me/password', (_call, path) =>
      path === '/admins/me/password' ? problemResponse(422, 'admin.wrong_current_password') : undefined,
    );

    await user.type(await screen.findByLabelText(/Aktuelles Passwort/), 'old password!');
    await user.type(screen.getByLabelText(/^Neues Passwort \*/), 'new password 123');
    await user.type(screen.getByLabelText(/Neues Passwort wiederholen/), 'new password 123');
    await user.click(screen.getByRole('button', { name: 'Passwort ändern' }));

    expect(await screen.findByText('Das aktuelle Passwort stimmt nicht.')).toBeInTheDocument();
  });

  it('changes the password and signs out, because every session has ended', async () => {
    const { user, double, tokenStore } = signedIn(DOMAIN_MASTER, '/admins/me/password', (_call, path) => {
      if (path === '/admins/me/password') {
        return emptyResponse(204);
      }

      return path === '/auth/logout' ? emptyResponse(204) : undefined;
    });

    await user.type(await screen.findByLabelText(/Aktuelles Passwort/), 'old password!');
    await user.type(screen.getByLabelText(/^Neues Passwort \*/), 'new password 123');
    await user.type(screen.getByLabelText(/Neues Passwort wiederholen/), 'new password 123');
    await user.click(screen.getByRole('button', { name: 'Passwort ändern' }));

    await waitFor(() => expect(tokenStore.read()).toBeNull());

    const [put] = double.callsTo('/api/v1/admins/me/password');

    expect(put!.method).toBe('PUT');
    expect(put!.body).toEqual({ current_password: 'old password!', new_password: 'new password 123' });
  });
});
