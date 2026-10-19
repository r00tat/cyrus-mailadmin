import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { defaultTranslator, createFormatters } from '../../i18n';
import { jsonResponse, problemResponse } from '../../test/fetchDouble';

import { READ_ONLY, SUPERUSER, renderSignedIn, type Session } from './operationsTestKit';

/** A6 — one repair-queue entry, through the whole application. */

const ENTRY = {
  id: 42,
  recorded_at: '2026-10-17T12:05:00Z',
  operation: 'account.delete',
  step: 'mailstore.delete_mailbox',
  subject: 'bert@example.test',
  intended: 'delete the mailbox after the rows',
  reached: 'rows deleted',
  reason: 'IMAP NO [SERVERBUG]',
  parameters: { account: 'bert@example.test', domain: 'example.test' },
  request_id: 'req-1234',
  status: 'open',
  retryable: true,
  attempts: 2,
  last_attempt_at: '2026-10-17T12:30:00Z',
  last_error: 'repair.retry_failed',
  resolved_at: null,
  resolved_by: null,
  resolution: null,
  note: null,
};

const RETRIED = { ...ENTRY, status: 'resolved', retryable: false, resolution: 'retried', resolved_by: 'root' };

function openEntry(session: Session, entry: unknown = ENTRY) {
  let current = entry;

  return renderSignedIn(session, '/maintenance/repairs/42', (call, path) => {
    if (call.method === 'GET' && path === '/api/v1/maintenance/repairs/42') {
      return jsonResponse(200, current);
    }

    if (call.method === 'POST' && path === '/api/v1/maintenance/repairs/42/retry') {
      current = RETRIED;

      return jsonResponse(200, RETRIED);
    }

    return undefined;
  });
}

describe('a repair-queue entry', () => {
  it('shows what it records, the last error through the catalogue and times with their zone', async () => {
    openEntry(SUPERUSER);

    expect(await screen.findByRole('heading', { level: 1, name: 'Eintrag 42' })).toBeInTheDocument();
    expect(await screen.findByText('Die Wiederholung ist erneut gescheitert. Der Eintrag bleibt offen.')).toBeInTheDocument();
    expect(screen.getByText('repair.retry_failed')).toBeInTheDocument();
    expect(screen.getByText('IMAP NO [SERVERBUG]')).toBeInTheDocument();

    const { dateTime } = createFormatters(defaultTranslator);

    expect(screen.getByText(dateTime('2026-10-17T12:30:00Z'))).toBeInTheDocument();

    const parameters = screen.getByRole('table', { name: 'Parameter einer Wiederholung' });

    expect(within(parameters).getByText('domain')).toBeInTheDocument();
    expect(within(parameters).getByText('example.test')).toBeInTheDocument();
  });

  it('links into the audit trail by request id, for a session that may read it', async () => {
    const { user } = openEntry(SUPERUSER);

    const link = await screen.findByRole('link', { name: 'Im Revisionsprotokoll ansehen' });

    expect(link).toHaveAttribute('href', '/audit?request_id=req-1234');

    await user.click(link);

    expect(await screen.findByRole('heading', { level: 1, name: 'Revisionsprotokoll' })).toBeInTheDocument();
  });

  it('offers neither actions nor the audit link without the permissions', async () => {
    openEntry(READ_ONLY);

    await screen.findByText('req-1234');

    expect(screen.queryByRole('link', { name: 'Im Revisionsprotokoll ansehen' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Wiederholen' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Abschließen' })).toBeNull();
  });

  it('offers no action on a resolved entry', async () => {
    openEntry(SUPERUSER, { ...ENTRY, status: 'resolved', retryable: false, resolution: 'dismissed', note: 'done by hand' });

    expect(await screen.findByText('done by hand')).toBeInTheDocument();
    expect(screen.getByText('abgeschlossen')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Wiederholen' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Abschließen' })).toBeNull();
  });

  it('asks for the mailbox name before a retry deletes it, then shows the result', async () => {
    const { double, user } = openEntry(SUPERUSER);

    await user.click(await screen.findByRole('button', { name: 'Wiederholen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Schritt wiederholen?' });

    await user.type(within(dialog).getByLabelText('Bestätigung'), 'bert@example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Wiederholen' }));

    await waitFor(() => expect(double.callsTo('/api/v1/maintenance/repairs/42/retry')).toHaveLength(1));
    expect(await screen.findByText('wiederholt')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Wiederholen' })).toBeNull();
  });

  it('reloads the entry when a resolve is refused because someone else resolved it', async () => {
    let current: unknown = ENTRY;
    const { double, user } = renderSignedIn(SUPERUSER, '/maintenance/repairs/42', (call, path) => {
      if (call.method === 'GET' && path === '/api/v1/maintenance/repairs/42') {
        return jsonResponse(200, current);
      }

      if (call.method === 'POST' && path === '/api/v1/maintenance/repairs/42/resolve') {
        current = { ...ENTRY, status: 'resolved', retryable: false, resolution: 'dismissed', resolved_by: 'other' };

        return problemResponse(409, 'repair.already_resolved');
      }

      return undefined;
    });

    await user.click(await screen.findByRole('button', { name: 'Abschließen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Eintrag abschließen?' });

    await user.click(within(dialog).getByRole('button', { name: 'Abschließen' }));

    expect(await within(dialog).findByText('Dieser Reparatureintrag ist bereits erledigt.')).toBeInTheDocument();
    await waitFor(() =>
      expect(double.callsTo('/api/v1/maintenance/repairs/42').filter((call) => call.method === 'GET')).toHaveLength(2),
    );
  });

  it('renders a 404 through the catalogue', async () => {
    renderSignedIn(SUPERUSER, '/maintenance/repairs/42', () => problemResponse(404, 'request.not_found'));

    expect(await screen.findByRole('alert')).toHaveTextContent('Der angeforderte Eintrag existiert nicht.');
  });
});
