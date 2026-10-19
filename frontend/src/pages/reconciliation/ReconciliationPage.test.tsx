import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { jsonResponse, problemResponse } from '../../test/fetchDouble';
import { DOMAIN_MASTER, SUPERUSER, renderSignedIn } from '../repairs/operationsTestKit';

/** A6 — the reconciliation report, through the whole application. */

const SUMMARY = {
  account_without_mailbox: 1,
  mailbox_without_account: 0,
  shadow_mailbox: 1,
  unresolvable_account: 0,
  ambiguous_username: 0,
  virtual_owner_missing: 0,
  virtual_target_missing: 2,
  virtual_unclassified: 0,
};

const REPORT = {
  checked_at: '2026-10-17T12:00:00Z',
  checked: { accounts: 1234, mailboxes: 1233, virtual_rows: 5000 },
  summary: SUMMARY,
  findings: [
    { category: 'virtual_target_missing', subject: 'info@example.test', domain: 'example.test', related: 'gone.user', reason: 'no_account' },
    { category: 'account_without_mailbox', subject: 'anna@example.test', domain: 'example.test', related: null, reason: null },
    { category: 'shadow_mailbox', subject: 'user/anna.example.test', domain: 'example.test', related: 'anna@example.test', reason: 'dotted' },
    { category: 'virtual_target_missing', subject: 'sales@example.test', domain: 'example.test', related: 'nobody@example.test', reason: 'no_address' },
  ],
};

const CLEAN = {
  ...REPORT,
  summary: Object.fromEntries(Object.keys(SUMMARY).map((key) => [key, 0])),
  findings: [],
};

const PATH = '/api/v1/maintenance/reconciliation';

describe('the reconciliation', () => {
  it('runs only when asked, and groups the findings by category in the contract order', async () => {
    const { double, user } = renderSignedIn(SUPERUSER, '/maintenance/reconciliation', (_call, path) =>
      path === PATH ? jsonResponse(200, REPORT) : undefined,
    );

    const start = await screen.findByRole('button', { name: 'Abgleich starten' });

    expect(screen.getByText(/läuft deshalb nur auf Anforderung/)).toBeInTheDocument();
    expect(double.callsTo(PATH)).toHaveLength(0);

    await user.click(start);

    const summary = await screen.findByRole('table', { name: 'Zusammenfassung' });

    expect(double.callsTo(PATH)).toHaveLength(1);
    // Every category, found or not.
    expect(within(summary).getAllByRole('row')).toHaveLength(9);
    expect(within(summary).getByText('Postfach ohne Konto')).toBeInTheDocument();
    expect(screen.getByText('Verglichen wurden 1.234 Konten, 1.233 Postfächer und 5.000 Adresszeilen.')).toBeInTheDocument();

    const groups = screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent);

    expect(groups).toEqual(['Zusammenfassung', 'Konto ohne Postfach', 'Schatten-Postfach', 'Ziel ohne Empfänger']);

    const targets = screen.getByRole('table', { name: 'Ziel ohne Empfänger' });

    expect(within(targets).getByText('gone.user')).toBeInTheDocument();
    expect(within(targets).getByText('no_address')).toBeInTheDocument();
    expect(screen.getByText('2 Befunde')).toBeInTheDocument();
    expect(screen.getAllByText('1 Befund')).toHaveLength(2);
    expect(screen.queryByRole('table', { name: 'Postfach ohne Konto' })).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Erneut abgleichen' }));
    await waitFor(() => expect(double.callsTo(PATH)).toHaveLength(2));
  });

  it('says so when nothing was found', async () => {
    const { user } = renderSignedIn(SUPERUSER, '/maintenance/reconciliation', (_call, path) =>
      path === PATH ? jsonResponse(200, CLEAN) : undefined,
    );

    await user.click(await screen.findByRole('button', { name: 'Abgleich starten' }));

    expect(await screen.findByText('Keine Abweichungen gefunden.')).toBeInTheDocument();
  });

  it('renders an unreachable mail store through the catalogue, without retrying on its own', async () => {
    const { double, user } = renderSignedIn(SUPERUSER, '/maintenance/reconciliation', (_call, path) =>
      path === PATH ? problemResponse(503, 'request.service_unavailable') : undefined,
    );

    await user.click(await screen.findByRole('button', { name: 'Abgleich starten' }));

    const alert = await screen.findByRole('alert');

    expect(alert).toHaveTextContent('Der Dienst ist vorübergehend nicht verfügbar.');
    expect(alert).toHaveTextContent('req-test');
    expect(double.callsTo(PATH)).toHaveLength(1);
  });

  it('is not shown to a domain master', async () => {
    renderSignedIn(DOMAIN_MASTER, '/maintenance/reconciliation');

    expect(await screen.findByRole('alert')).toHaveTextContent('Keine Berechtigung');
    expect(screen.queryByRole('button', { name: 'Abgleich starten' })).toBeNull();
  });
});
