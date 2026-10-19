import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { jsonResponse, problemResponse } from '../../test/fetchDouble';

import { DOMAIN_MASTER, READ_ONLY, SUPERUSER, queryOf, renderSignedIn, type Session } from './operationsTestKit';

/** A6 — the repair queue, through the whole application. */

function entry(overrides: Record<string, unknown> = {}) {
  return {
    id: 7,
    recorded_at: '2026-10-17T12:05:00Z',
    operation: 'account.create',
    step: 'mailstore.default_folders',
    subject: 'anna@example.test',
    intended: 'create the default folders',
    reached: 'mailbox created',
    reason: 'IMAP NO',
    parameters: { account: 'anna@example.test', domain: 'example.test', folders: 'Sent,Trash' },
    request_id: 'req-abc',
    status: 'open',
    retryable: true,
    attempts: 0,
    last_attempt_at: null,
    last_error: null,
    resolved_at: null,
    resolved_by: null,
    resolution: null,
    note: null,
    ...overrides,
  };
}

const DELETE_STEP = entry({
  id: 8,
  operation: 'account.delete',
  step: 'mailstore.delete_mailbox',
  subject: 'bert@example.test',
});

const MANUAL = entry({ id: 9, operation: 'alias.update', step: 'virtual.restore', subject: 'info@example.test', retryable: false, parameters: {} });

const RESOLVED = entry({
  id: 3,
  subject: 'carla@example.test',
  status: 'resolved',
  retryable: false,
  resolution: 'dismissed',
  resolved_at: '2026-10-17T13:00:00Z',
  resolved_by: 'root',
  note: 'fixed by hand',
});

function page(items: unknown[]) {
  return { items, meta: { page: 1, per_page: 50, total: items.length } };
}

function openQueue(session: Session, items: unknown[] = [entry(), DELETE_STEP, MANUAL, RESOLVED], initialEntry = '/maintenance/repairs') {
  return renderSignedIn(session, initialEntry, (call, path) => {
    if (call.method === 'GET' && path === '/api/v1/maintenance/repairs') {
      return jsonResponse(200, page(items));
    }

    if (call.method === 'POST' && path === '/api/v1/maintenance/repairs/7/retry') {
      return jsonResponse(200, entry({ status: 'resolved', retryable: false, resolution: 'retried' }));
    }

    if (call.method === 'POST' && path === '/api/v1/maintenance/repairs/8/retry') {
      return problemResponse(409, 'repair.state_changed');
    }

    if (call.method === 'POST' && path === '/api/v1/maintenance/repairs/9/resolve') {
      return jsonResponse(200, { ...MANUAL, status: 'resolved', resolution: 'dismissed' });
    }

    return undefined;
  });
}

async function table(): Promise<HTMLElement> {
  return screen.findByRole('table', { name: 'Reparaturwarteschlange' });
}

describe('the repair queue', () => {
  it('asks for the newest entries first and shows them with their state', async () => {
    const { double } = openQueue(SUPERUSER);
    const list = await table();

    expect(await within(list).findByText('anna@example.test')).toBeInTheDocument();
    expect(queryOf(double.callsTo('/api/v1/maintenance/repairs')[0])).toEqual({
      page: '1',
      per_page: '50',
      sort: 'id',
      order: 'desc',
    });

    const resolvedRow = within(list).getByText('3').closest('tr') as HTMLElement;

    expect(within(resolvedRow).getByText('abgeschlossen')).toBeInTheDocument();
    expect(within(list).getAllByText('offen')).toHaveLength(3);
  });

  it('sends the filters and the sort it was given', async () => {
    const { double, user } = openQueue(SUPERUSER, [entry()], '/maintenance/repairs?status=open&operation=account.&sort=bogus');

    await table();

    // An unknown sort field in the URL is dropped, not forwarded (rule 2).
    expect(queryOf(double.callsTo('/api/v1/maintenance/repairs')[0])).toEqual({
      page: '1',
      per_page: '50',
      sort: 'id',
      order: 'desc',
      status: 'open',
      operation: 'account.',
    });

    await user.click(screen.getByRole('button', { name: /Vorgang/ }));

    await waitFor(() => {
      expect(queryOf(double.callsTo('/api/v1/maintenance/repairs').at(-1))).toMatchObject({ sort: 'operation', order: 'asc' });
    });
  });

  it('does not send an operation or step the contract pattern refuses', async () => {
    const { double } = openQueue(SUPERUSER, [entry()], '/maintenance/repairs?operation=account%20delete&step=mailstore.');

    await table();

    expect(queryOf(double.callsTo('/api/v1/maintenance/repairs')[0])).toEqual({
      page: '1',
      per_page: '50',
      sort: 'id',
      order: 'desc',
    });
    expect(screen.getByLabelText('Vorgang (Anfang)')).toHaveAccessibleDescription(/etwa „account.create“/);
    expect(screen.getByLabelText('Schritt')).toHaveAccessibleDescription(/etwa „account.create“/);
  });

  it('says so when the queue is empty', async () => {
    openQueue(SUPERUSER, []);

    expect(await screen.findByText('Keine Einträge in der Warteschlange.')).toBeInTheDocument();
  });

  it('renders a failure through the catalogue, with the request id', async () => {
    renderSignedIn(SUPERUSER, '/maintenance/repairs', () => problemResponse(500, 'request.internal_error'));

    const alert = await screen.findByRole('alert');

    expect(alert).toHaveTextContent('Im Server ist ein unerwarteter Fehler aufgetreten.');
    expect(alert).toHaveTextContent('req-test');
  });

  it('offers retry only where the server can retry, and resolve on every open entry', async () => {
    openQueue(SUPERUSER);
    const list = await table();

    await within(list).findByText('anna@example.test');

    expect(within(list).getByRole('button', { name: 'Eintrag 7 wiederholen' })).toBeInTheDocument();
    expect(within(list).queryByRole('button', { name: 'Eintrag 9 wiederholen' })).toBeNull();
    expect(within(list).getByText('nur abschließbar')).toBeInTheDocument();
    expect(within(list).getByRole('button', { name: 'Eintrag 9 abschließen' })).toBeInTheDocument();
    expect(within(list).queryByRole('button', { name: 'Eintrag 3 abschließen' })).toBeNull();
  });

  it('shows no write controls to a session without maintenance.write', async () => {
    openQueue(READ_ONLY);
    const list = await table();

    await within(list).findByText('anna@example.test');

    expect(within(list).queryByRole('button', { name: /wiederholen|abschließen/ })).toBeNull();
    expect(within(list).queryByRole('columnheader', { name: 'Aktionen' })).toBeNull();
  });

  it('is not shown to a domain master', async () => {
    const { double } = openQueue(DOMAIN_MASTER);

    expect(await screen.findByRole('alert')).toHaveTextContent('Keine Berechtigung');
    expect(double.callsTo('/api/v1/maintenance/repairs')).toHaveLength(0);
  });

  it('retries after a confirmation, and refreshes the list', async () => {
    const { double, user } = openQueue(SUPERUSER);
    const list = await table();

    await user.click(await within(list).findByRole('button', { name: 'Eintrag 7 wiederholen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Schritt wiederholen?' });

    expect(dialog).toHaveTextContent('mailstore.default_folders');
    expect(double.callsTo('/api/v1/maintenance/repairs/7/retry')).toHaveLength(0);

    await user.click(within(dialog).getByRole('button', { name: 'Wiederholen' }));

    expect(await screen.findByText('Der Schritt wurde wiederholt; der Eintrag ist erledigt.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/maintenance/repairs/7/retry')).toHaveLength(1);
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Schritt wiederholen?' })).toBeNull());
    await waitFor(() => expect(double.callsTo('/api/v1/maintenance/repairs').length).toBeGreaterThan(1));
  });

  it('makes the name be typed before a retry deletes a mailbox, and shows a refusal in the dialog', async () => {
    const { double, user } = openQueue(SUPERUSER);
    const list = await table();

    await user.click(await within(list).findByRole('button', { name: 'Eintrag 8 wiederholen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Schritt wiederholen?' });
    const confirm = within(dialog).getByRole('button', { name: 'Wiederholen' });

    expect(dialog).toHaveTextContent('löscht das Postfach samt aller darin liegenden Mails');
    expect(confirm).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Bestätigung'), 'bert@example');
    expect(confirm).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Bestätigung'), '.test');
    expect(confirm).toBeEnabled();

    await user.click(confirm);

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Der Zustand passt nicht mehr zum Reparatureintrag. Es wurde nichts geändert.',
    );
    expect(double.callsTo('/api/v1/maintenance/repairs/8/retry')).toHaveLength(1);
    expect(screen.getByRole('dialog', { name: 'Schritt wiederholen?' })).toBeInTheDocument();
  });

  it('resolves with a note, and sends an empty object without one', async () => {
    const { double, user } = openQueue(SUPERUSER);
    const list = await table();

    await user.click(await within(list).findByRole('button', { name: 'Eintrag 9 abschließen' }));

    let dialog = await screen.findByRole('dialog', { name: 'Eintrag abschließen?' });

    await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(double.callsTo('/api/v1/maintenance/repairs/9/resolve')).toHaveLength(0);

    await user.click(within(list).getByRole('button', { name: 'Eintrag 9 abschließen' }));
    dialog = await screen.findByRole('dialog', { name: 'Eintrag abschließen?' });
    await user.type(within(dialog).getByLabelText('Notiz (optional)'), '  restored the alias by hand  ');
    await user.click(within(dialog).getByRole('button', { name: 'Abschließen' }));

    expect(await screen.findByText('Der Eintrag ist abgeschlossen.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/maintenance/repairs/9/resolve')[0]?.body).toEqual({ note: 'restored the alias by hand' });

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await user.click(within(list).getByRole('button', { name: 'Eintrag 9 abschließen' }));
    dialog = await screen.findByRole('dialog', { name: 'Eintrag abschließen?' });
    await user.click(within(dialog).getByRole('button', { name: 'Abschließen' }));

    await waitFor(() => expect(double.callsTo('/api/v1/maintenance/repairs/9/resolve')).toHaveLength(2));
    expect(double.callsTo('/api/v1/maintenance/repairs/9/resolve')[1]?.body).toEqual({});
  });

  it('puts a rejected note on the note field', async () => {
    const { user } = renderSignedIn(SUPERUSER, '/maintenance/repairs', (call, path) => {
      if (path === '/api/v1/maintenance/repairs') {
        return jsonResponse(200, page([MANUAL]));
      }

      if (call.method === 'POST') {
        return problemResponse(422, 'request.validation_failed', { errors: { 'body.note': 'maxLength' } });
      }

      return undefined;
    });

    await user.click(await screen.findByRole('button', { name: 'Eintrag 9 abschließen' }));
    const dialog = await screen.findByRole('dialog', { name: 'Eintrag abschließen?' });

    await user.type(within(dialog).getByLabelText('Notiz (optional)'), 'x');
    await user.click(within(dialog).getByRole('button', { name: 'Abschließen' }));

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Die Eingaben sind unvollständig oder unzulässig.');
    expect(within(dialog).getByLabelText('Notiz (optional)')).toHaveAttribute('aria-invalid', 'true');
    expect(within(dialog).getByLabelText('Notiz (optional)')).toHaveValue('x');
  });

  it('opens an entry from its row', async () => {
    const { user } = renderSignedIn(SUPERUSER, '/maintenance/repairs', (_call, path) => {
      if (path === '/api/v1/maintenance/repairs') {
        return jsonResponse(200, page([entry()]));
      }

      if (path === '/api/v1/maintenance/repairs/7') {
        return jsonResponse(200, entry());
      }

      return undefined;
    });

    await user.click(await screen.findByText('anna@example.test'));

    expect(await screen.findByRole('heading', { level: 1, name: 'Eintrag 7' })).toBeInTheDocument();
  });
});
