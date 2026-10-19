import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { emptyResponse, jsonResponse, problemResponse } from '../../test/fetchDouble';

import { DOMAIN_MASTER, PAGE_META, SUPERUSER, queryOf, signedIn } from './testkit';

/** A1 — the domain views, through the whole application. */

const DOMAIN = {
  name: 'example.test',
  account_count: 2,
  max_accounts: 10,
  default_account_quota_kib: null,
  domain_quota_kib: null,
  naming_scheme: 'inherited',
  effective_naming_scheme: 'fqun',
  conventional_name: true,
  default_folders: ['Sent', 'Trash'],
  legacy_prefix: '',
  transport: '',
  free_names: false,
  free_address: false,
};

const LEGACY = { ...DOMAIN, name: 'Old_Name.test', account_count: 0, conventional_name: false };

describe('the domain list', () => {
  it('asks for the sorted first page and shows each domain with its legacy flag', async () => {
    const { double } = signedIn(SUPERUSER, '/domains', (_call, path) =>
      path === '/domains' ? jsonResponse(200, { items: [DOMAIN, LEGACY], meta: { ...PAGE_META, total: 2 } }) : undefined,
    );

    expect(await screen.findByText('example.test')).toBeInTheDocument();
    expect(screen.getByText('Old_Name.test')).toBeInTheDocument();
    expect(screen.getByText('Altname')).toBeInTheDocument();

    const [call] = double.callsTo('/api/v1/domains');

    expect(queryOf(call!)).toMatchObject({ sort: 'name', order: 'asc' });
  });

  it('offers creating to a superuser only', async () => {
    signedIn(SUPERUSER, '/domains', (_call, path) =>
      path === '/domains' ? jsonResponse(200, { items: [], meta: { ...PAGE_META, total: 0 } }) : undefined,
    );

    expect(await screen.findByText('Noch keine Domain.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Domain anlegen' })).toHaveAttribute('href', '/domains/new');
  });

  it('hides creating from a domain master', async () => {
    signedIn(DOMAIN_MASTER, '/domains', (_call, path) =>
      path === '/domains' ? jsonResponse(200, { items: [DOMAIN], meta: PAGE_META }) : undefined,
    );

    expect(await screen.findByText('example.test')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Domain anlegen' })).not.toBeInTheDocument();
  });

  it('shows a failed load through the error catalogue', async () => {
    signedIn(SUPERUSER, '/domains', (_call, path) =>
      path === '/domains' ? problemResponse(422, 'request.validation_failed') : undefined,
    );

    expect(await screen.findByText('Die Eingaben sind unvollständig oder unzulässig.')).toBeInTheDocument();
  });
});

describe('creating a domain', () => {
  it('sends only what was filled in and shows the next steps as a checklist', async () => {
    const { user, double } = signedIn(SUPERUSER, '/domains/new', (call, path) =>
      call.method === 'POST' && path === '/domains'
        ? jsonResponse(201, {
            domain: { ...DOMAIN, name: 'new.test', account_count: 0 },
            next_steps: ['salt_pillar', 'dns_mx'],
          })
        : undefined,
    );

    await user.type(await screen.findByRole('textbox', { name: /Domainname/ }), 'new.test');
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'new.test ist angelegt' })).toBeInTheDocument();
    expect(double.callsTo('/api/v1/domains')[0]!.body).toEqual({ name: 'new.test' });

    expect(screen.getByRole('checkbox', { name: /Domain im Salt-Pillar eintragen/ })).not.toBeChecked();
    expect(screen.getByRole('checkbox', { name: /MX-Eintrag setzen/ })).toBeInTheDocument();
    expect(screen.queryByText('DKIM-Schlüssel veröffentlichen')).not.toBeInTheDocument();
  });

  it('refuses an empty name before asking the server', async () => {
    const { user, double } = signedIn(SUPERUSER, '/domains/new', () => undefined);

    await user.click(await screen.findByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByText('Pflichtangabe.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/domains')).toHaveLength(0);
  });

  it('shows a refused name on the field, translated', async () => {
    const { user } = signedIn(SUPERUSER, '/domains/new', (call, path) =>
      call.method === 'POST' && path === '/domains'
        ? problemResponse(409, 'domain.name_taken', { errors: { 'body.name': 'pattern' } })
        : undefined,
    );

    await user.type(await screen.findByRole('textbox', { name: /Domainname/ }), 'example.test');
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByText('Diese Domain existiert bereits.')).toBeInTheDocument();
    expect(screen.getByText('Die Eingabe hat nicht das erwartete Format.')).toBeInTheDocument();
  });
});

function domainApi(options: { remove?: () => Response; rename?: () => Response; patch?: () => Response } = {}) {
  return (call: { method: string }, path: string): Response | undefined => {
    if (path !== '/domains/example.test') {
      return undefined;
    }

    if (call.method === 'GET') {
      return jsonResponse(200, DOMAIN, { ETag: '"v1"' });
    }

    if (call.method === 'DELETE') {
      return options.remove?.();
    }

    if (call.method === 'PATCH') {
      return options.patch?.() ?? options.rename?.();
    }

    return undefined;
  };
}

describe('one domain', () => {
  it('hides every action from a domain master', async () => {
    signedIn(DOMAIN_MASTER, '/domains/example.test', domainApi());

    expect(await screen.findByRole('heading', { level: 2, name: 'Grenzen' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Löschen' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Umbenennen' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Bearbeiten' })).not.toBeInTheDocument();
  });

  it('deletes only after the name is typed, with the version it showed', async () => {
    const { user, double } = signedIn(SUPERUSER, '/domains/example.test', (call, path) =>
      domainApi({ remove: () => emptyResponse(204) })(call, path)
      ?? (path === '/domains' ? jsonResponse(200, { items: [], meta: { ...PAGE_META, total: 0 } }) : undefined),
    );

    await user.click(await screen.findByRole('button', { name: 'Löschen' }));

    const dialog = await screen.findByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Löschen' });

    expect(confirm).toBeDisabled();

    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'example.test');
    await user.click(confirm);

    expect(await screen.findByRole('heading', { level: 1, name: 'Domains' })).toBeInTheDocument();

    const [remove] = double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'DELETE');

    expect(remove!.headers.get('If-Match')).toBe('"v1"');
  });

  it('forgets the deleted domain wherever it is offered or shown, not only in the list', async () => {
    const { user, queryClient } = signedIn(SUPERUSER, '/domains/example.test', (call, path) =>
      domainApi({ remove: () => emptyResponse(204) })(call, path)
      ?? (path === '/domains' ? jsonResponse(200, { items: [], meta: { ...PAGE_META, total: 0 } }) : undefined),
    );
    const cached = [['domains', 'options'], ['admins', 'list'], ['accounts', 'list'], ['addresses', 'list']];

    for (const key of cached) {
      queryClient.setQueryData(key, { items: [DOMAIN], meta: PAGE_META });
    }

    await user.click(await screen.findByRole('button', { name: 'Löschen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Löschen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'Domains' })).toBeInTheDocument();

    for (const key of cached) {
      expect(queryClient.getQueryState(key)?.isInvalidated, key.join('.')).toBe(true);
    }
  });

  it('shows why a domain with addresses cannot be deleted', async () => {
    const { user } = signedIn(SUPERUSER, '/domains/example.test', domainApi({
      remove: () => problemResponse(409, 'domain.still_has_addresses'),
    }));

    await user.click(await screen.findByRole('button', { name: 'Löschen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Löschen' }));

    expect(await within(dialog).findByText(/Die Domain hat noch Adressen/)).toBeInTheDocument();
  });

  it('renames with an explicit confirmation and follows the new name', async () => {
    const renamed = { ...DOMAIN, name: 'renamed.test' };
    const { user, double } = signedIn(SUPERUSER, '/domains/example.test', (call, path) =>
      domainApi({ rename: () => jsonResponse(200, renamed, { ETag: '"v2"' }) })(call, path)
      ?? (path === '/domains/renamed.test' ? jsonResponse(200, renamed, { ETag: '"v2"' }) : undefined),
    );

    await user.click(await screen.findByRole('button', { name: 'Umbenennen' }));

    const dialog = await screen.findByRole('dialog');

    expect(within(dialog).getByText(/Alle 2 Postfächer der Domain werden im Mailspeicher umbenannt/)).toBeInTheDocument();

    await user.type(within(dialog).getByRole('textbox', { name: 'Neuer Domainname' }), 'renamed.test');
    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Umbenennen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'renamed.test' })).toBeInTheDocument();

    const [patch] = double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'PATCH');

    expect(patch!.body).toEqual({ name: 'renamed.test', confirm_rename: true });
    expect(patch!.headers.get('If-Match')).toBe('"v1"');
    // The old name no longer exists: it is not asked for again after the rename.
    expect(double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'GET')).toHaveLength(1);
  });

  it('makes an incomplete rename impossible to miss, with the way to the repair queue', async () => {
    const { user } = signedIn(SUPERUSER, '/domains/example.test', domainApi({
      rename: () => problemResponse(409, 'domain.rename_incomplete'),
    }));

    await user.click(await screen.findByRole('button', { name: 'Umbenennen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: 'Neuer Domainname' }), 'renamed.test');
    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Umbenennen' }));

    expect(await screen.findByText('Umbenennung nicht abgeschlossen')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getByText(/Was sich nicht zurücknehmen ließ, steht in der Reparaturwarteschlange/)).toBeInTheDocument();
    expect(screen.getByText('Vorgangsnummer: req-test')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Zur Reparaturwarteschlange' })).toHaveAttribute('href', '/maintenance/repairs');
  });

  it('refuses renaming to the same name without asking the server', async () => {
    const { user, double } = signedIn(SUPERUSER, '/domains/example.test', domainApi());

    await user.click(await screen.findByRole('button', { name: 'Umbenennen' }));

    const dialog = await screen.findByRole('dialog');

    await user.type(within(dialog).getByRole('textbox', { name: 'Neuer Domainname' }), 'example.test');
    await user.type(within(dialog).getByRole('textbox', { name: 'Bestätigung' }), 'example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Umbenennen' }));

    expect(await within(dialog).findByText('Der neue Name ist derselbe wie der bisherige.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'PATCH')).toHaveLength(0);
  });
});

describe('editing a domain', () => {
  it('sends only the changed field, with If-Match', async () => {
    const { user, double } = signedIn(SUPERUSER, '/domains/example.test/edit', domainApi({
      patch: () => jsonResponse(200, { ...DOMAIN, max_accounts: 20 }, { ETag: '"v2"' }),
    }));

    const field = await screen.findByRole('textbox', { name: 'Höchstzahl Konten' });

    await user.clear(field);
    await user.type(field, '20');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    await waitFor(() =>
      expect(double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'PATCH')).toHaveLength(1),
    );

    const [patch] = double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'PATCH');

    expect(patch!.body).toEqual({ max_accounts: 20 });
    expect(patch!.headers.get('If-Match')).toBe('"v1"');
  });

  it('says so instead of sending an empty change', async () => {
    const { user, double } = signedIn(SUPERUSER, '/domains/example.test/edit', domainApi());

    await screen.findByRole('textbox', { name: 'Höchstzahl Konten' });
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText('Es wurde nichts geändert.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/domains/example.test').filter((call) => call.method === 'PATCH')).toHaveLength(0);
  });

  it('offers a reload when someone else changed the domain first', async () => {
    const { user } = signedIn(SUPERUSER, '/domains/example.test/edit', domainApi({
      patch: () => problemResponse(412, 'request.precondition_failed'),
    }));

    const field = await screen.findByRole('textbox', { name: 'Höchstzahl Konten' });

    await user.clear(field);
    await user.type(field, '20');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText(/von jemand anderem geändert/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Neu laden' }));

    expect(await screen.findByText(/Das Formular zeigt jetzt den aktuellen Stand/)).toBeInTheDocument();
  });
});
