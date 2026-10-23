import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { jsonResponse, problemResponse } from '../../test/fetchDouble';
import { DOMAIN_MASTER, PAGE_META, SUPERUSER, queryOf, signedIn } from '../domains/testkit';

/** A5 — the mailing list views, through the whole application. */

const TEAM = {
  name: 'team',
  tag: 'intern',
  address: 'team@example.test',
  members: ['anna@example.test', 'bert@example.test'],
  unusable_members: [],
  address_usable: true,
};

const OLD = {
  name: 'old',
  tag: '',
  address: 'old',
  members: ['anna@example.test', '|/usr/bin/true'],
  unusable_members: ['|/usr/bin/true'],
  address_usable: false,
};

const CONFIG_HINT = /wirken erst nach dem nächsten Lauf der Konfigurationsverwaltung/;

/** What every successful list write answers with (U-59). */
const PENDING_RUN = { 'X-Mailadmin-Effect': 'pending-configuration-run' };

function listApi(options: { post?: () => Response; patch?: () => Response; remove?: () => Response } = {}) {
  return (call: { method: string }, path: string): Response | undefined => {
    if (path === '/lists') {
      return call.method === 'POST'
        ? options.post?.()
        : jsonResponse(200, { items: [TEAM, OLD], meta: { ...PAGE_META, total: 2 } });
    }

    if (path === '/lists/team%40example.test') {
      if (call.method === 'GET') {
        return jsonResponse(200, TEAM, { ETag: '"l1"' });
      }

      return call.method === 'PATCH' ? options.patch?.() : options.remove?.();
    }

    if (path === '/lists/old' && call.method === 'GET') {
      return jsonResponse(200, OLD, { ETag: '"o1"' });
    }

    return undefined;
  };
}

describe('the mailing list list', () => {
  it('lists every list, flags unusable values and says when changes take effect', async () => {
    const { double } = signedIn(SUPERUSER, '/lists', listApi());

    expect(await screen.findByText('team@example.test')).toBeInTheDocument();
    expect(screen.getByText(CONFIG_HINT)).toBeInTheDocument();

    const old = screen.getByText('old', { selector: 'td' }).closest('tr')!;

    expect(within(old).getByText('keine Mailadresse')).toBeInTheDocument();
    expect(within(old).getByText('1 unbrauchbar')).toBeInTheDocument();
    expect(within(screen.getByText('team@example.test').closest('tr')!).getByText('2 Mitglieder')).toBeInTheDocument();

    expect(queryOf(double.callsTo('/api/v1/lists')[0]!)).toMatchObject({ sort: 'name' });
  });

  it('is closed to a domain master', async () => {
    signedIn(DOMAIN_MASTER, '/lists', listApi());

    expect(await screen.findByText('Keine Berechtigung')).toBeInTheDocument();
  });

  it('shows a failed load through the error catalogue', async () => {
    signedIn(SUPERUSER, '/lists', (_call, path) => (path === '/lists' ? problemResponse(422, 'request.validation_failed') : undefined));

    expect(await screen.findByText('Die Eingaben sind unvollständig oder unzulässig.')).toBeInTheDocument();
  });

  it('creates a list with one member per line', async () => {
    const created = { ...TEAM, name: 'new', address: 'new@example.test' };
    const { user, double } = signedIn(SUPERUSER, '/lists', (call, path) =>
      listApi({ post: () => jsonResponse(201, created, { ETag: '"n1"', ...PENDING_RUN }) })(call, path)
      ?? (path === '/lists/new%40example.test' ? jsonResponse(200, created, { ETag: '"n1"' }) : undefined),
    );

    await user.click(await screen.findByRole('button', { name: 'Liste anlegen' }));

    const dialog = await screen.findByRole('dialog');

    expect(within(dialog).getByText(CONFIG_HINT)).toBeInTheDocument();

    await user.type(within(dialog).getByRole('textbox', { name: /^Name/ }), 'new');
    await user.type(within(dialog).getByRole('textbox', { name: /^Adresse/ }), 'new@example.test');
    await user.type(within(dialog).getByRole('textbox', { name: 'Mitglieder' }), 'anna@example.test{Enter}{Enter} bert@example.test ');
    await user.click(within(dialog).getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'new' })).toBeInTheDocument();
    expect(screen.getByText('Liste new angelegt. Sie stellt nach dem nächsten Konfigurationslauf zu.')).toBeInTheDocument();

    const [post] = double.callsTo('/api/v1/lists').filter((call) => call.method === 'POST');

    expect(post!.body).toEqual({
      name: 'new',
      address: 'new@example.test',
      tag: '',
      members: ['anna@example.test', 'bert@example.test'],
    });
  });

  it('shows a refused member on the member field', async () => {
    const { user } = signedIn(SUPERUSER, '/lists', listApi({
      post: () => problemResponse(422, 'list.invalid_member', { errors: { 'body.members.0': 'format' } }),
    }));

    await user.click(await screen.findByRole('button', { name: 'Liste anlegen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: /^Name/ }), 'new');
    await user.type(within(dialog).getByRole('textbox', { name: /^Adresse/ }), 'new@example.test');
    await user.type(within(dialog).getByRole('textbox', { name: 'Mitglieder' }), '|/bin/sh');
    await user.click(within(dialog).getByRole('button', { name: 'Anlegen' }));

    expect(await within(dialog).findByText('Mindestens eine Mitgliedsadresse ist unzulässig.')).toBeInTheDocument();
    expect(within(dialog).getByText('Die Eingabe hat nicht das erwartete Format.')).toBeInTheDocument();
  });
});

describe('one mailing list', () => {
  it('warns about values it shows but would not accept', async () => {
    signedIn(SUPERUSER, '/lists/old', listApi());

    expect(await screen.findByText(/Die gespeicherte Adresse ist keine Mailadresse/)).toBeInTheDocument();
    expect(screen.getByText(/Ein Eintrag ist keine Mailadresse: \|\/usr\/bin\/true/)).toBeInTheDocument();
    expect(screen.getByText(CONFIG_HINT)).toBeInTheDocument();
  });

  it('sends only what changed — members untouched stay out of the request', async () => {
    const { user, double } = signedIn(SUPERUSER, '/lists/team%40example.test', listApi({
      patch: () => jsonResponse(200, { ...TEAM, tag: 'extern' }, { ETag: '"l2"', ...PENDING_RUN }),
    }));

    const tag = await screen.findByRole('textbox', { name: 'Kennzeichen' });

    await user.clear(tag);
    await user.type(tag, 'extern');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    await waitFor(() => expect(double.callsTo('/api/v1/lists/team%40example.test').filter((call) => call.method === 'PATCH')).toHaveLength(1));

    const [patch] = double.callsTo('/api/v1/lists/team%40example.test').filter((call) => call.method === 'PATCH');

    expect(patch!.body).toEqual({ tag: 'extern' });
    expect(patch!.headers.get('If-Match')).toBe('"l1"');
    expect(await screen.findByText(/Die Änderung wirkt nach dem nächsten Konfigurationslauf/)).toBeInTheDocument();
  });

  it('sends the whole member list when it changed', async () => {
    const { user, double } = signedIn(SUPERUSER, '/lists/team%40example.test', listApi({
      patch: () => jsonResponse(200, TEAM, { ETag: '"l2"' }),
    }));

    await user.type(await screen.findByRole('textbox', { name: 'Mitglieder' }), '{Enter}carla@example.test');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    await waitFor(() => expect(double.callsTo('/api/v1/lists/team%40example.test').filter((call) => call.method === 'PATCH')).toHaveLength(1));

    const [patch] = double.callsTo('/api/v1/lists/team%40example.test').filter((call) => call.method === 'PATCH');

    expect(patch!.body).toEqual({ members: ['anna@example.test', 'bert@example.test', 'carla@example.test'] });
    // No `X-Mailadmin-Effect` on this answer: the confirmation follows the header, not a fixed text.
    expect(await screen.findByText('Liste team gespeichert.')).toBeInTheDocument();
  });

  it('offers a reload when someone else changed the list first', async () => {
    const { user } = signedIn(SUPERUSER, '/lists/team%40example.test', listApi({
      patch: () => problemResponse(412, 'request.precondition_failed'),
    }));

    const tag = await screen.findByRole('textbox', { name: 'Kennzeichen' });

    await user.type(tag, 'x');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText(/von jemand anderem geändert/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Neu laden' }));

    expect(await screen.findByText('Die Liste wurde neu geladen. Prüfe den Stand und ändere erneut.')).toBeInTheDocument();
  });

  it('deletes after a confirmation that says the address keeps delivering for now', async () => {
    const { user, double } = signedIn(SUPERUSER, '/lists/team%40example.test', listApi({
      remove: () => new Response(null, { status: 204, headers: PENDING_RUN }),
    }));

    await user.click(await screen.findByRole('button', { name: 'Löschen' }));

    const dialog = await screen.findByRole('dialog');

    expect(within(dialog).getByText(/team@example.test stellt bis zum nächsten Lauf der Konfigurationsverwaltung weiter zu/)).toBeInTheDocument();
    expect(double.callsTo('/api/v1/lists/team%40example.test').filter((call) => call.method === 'DELETE')).toHaveLength(0);

    await user.click(within(dialog).getByRole('button', { name: 'Löschen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'Mailinglisten' })).toBeInTheDocument();

    const [remove] = double.callsTo('/api/v1/lists/team%40example.test').filter((call) => call.method === 'DELETE');

    expect(remove!.headers.get('If-Match')).toBe('"l1"');
    expect(await screen.findByText('Liste team gelöscht. Sie stellt bis zum nächsten Konfigurationslauf weiter zu.')).toBeInTheDocument();
  });
});
