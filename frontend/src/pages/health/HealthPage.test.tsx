import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { createFormatters, defaultTranslator } from '../../i18n';
import { jsonResponse, problemResponse } from '../../test/fetchDouble';
import { DOMAIN_MASTER, SUPERUSER, renderSignedIn } from '../repairs/operationsTestKit';

/** A6 — the detailed health view, through the whole application. */

const DEGRADED = {
  status: 'degraded',
  checked_at: '2026-10-17T12:00:00Z',
  checks: [
    { component: 'database', status: 'ok', code: null, duration_ms: 3 },
    { component: 'imap', status: 'error', code: 'request.service_unavailable', duration_ms: 1500 },
    { component: 'managesieve', status: 'skipped', code: 'mailstore.something_new', duration_ms: 0 },
  ],
};

const PATH = '/api/v1/maintenance/health';

describe('the health view', () => {
  it('shows every probe, with its code through the catalogue and the time with its zone', async () => {
    const { double, user } = renderSignedIn(SUPERUSER, '/maintenance/health', (_call, path) =>
      path === PATH ? jsonResponse(200, DEGRADED) : undefined,
    );

    const table = await screen.findByRole('table', { name: 'Systemzustand' });

    expect(screen.getByText('Mindestens eine Prüfung ist nicht bestanden.')).toBeInTheDocument();

    const imap = within(table).getByText('IMAP').closest('tr') as HTMLElement;

    expect(within(imap).getByText('Fehler')).toBeInTheDocument();
    expect(within(imap).getByText('Der Dienst ist vorübergehend nicht verfügbar.')).toBeInTheDocument();
    expect(within(imap).getByText('1.500 ms')).toBeInTheDocument();

    // A code this build does not know is shown as it came, not dropped.
    const sieve = within(table).getByText('ManageSieve').closest('tr') as HTMLElement;

    expect(within(sieve).getAllByText('mailstore.something_new')).toHaveLength(2);
    expect(within(sieve).getByText('übersprungen')).toBeInTheDocument();

    const { dateTime } = createFormatters(defaultTranslator);

    expect(screen.getByText(`Geprüft: ${dateTime('2026-10-17T12:00:00Z')}`)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Erneut prüfen' }));
    await waitFor(() => expect(double.callsTo(PATH)).toHaveLength(2));
  });

  it('says when everything passed', async () => {
    renderSignedIn(SUPERUSER, '/maintenance/health', (_call, path) =>
      path === PATH
        ? jsonResponse(200, { ...DEGRADED, status: 'ok', checks: [{ component: 'database', status: 'ok', code: null, duration_ms: 2 }] })
        : undefined,
    );

    expect(await screen.findByText('Alle Prüfungen bestanden.')).toBeInTheDocument();
  });

  it('renders a failed request through the catalogue', async () => {
    renderSignedIn(SUPERUSER, '/maintenance/health', () => problemResponse(500, 'request.internal_error'));

    expect(await screen.findByRole('alert')).toHaveTextContent('Im Server ist ein unerwarteter Fehler aufgetreten.');
  });

  it('is not shown to a domain master', async () => {
    const { double } = renderSignedIn(DOMAIN_MASTER, '/maintenance/health');

    expect(await screen.findByRole('alert')).toHaveTextContent('Keine Berechtigung');
    expect(double.callsTo(PATH)).toHaveLength(0);
  });
});
