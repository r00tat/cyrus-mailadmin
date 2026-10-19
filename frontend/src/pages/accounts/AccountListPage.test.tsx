import { cleanup, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { jsonResponse, problemResponse } from '../../test/fetchDouble';

import { ACCOUNT, DOMAIN_MASTER, READER, SUPERUSER, page, queryOf, signedIn } from './testKit';

const LEGACY = {
  ...ACCOUNT,
  id: 'bert.example.test',
  address: 'bert@example.test',
  local_part: 'bert',
  password_format: 'md5crypt' as const,
  password_format_current: false,
  password_format_weak: true,
  enabled: false,
  disabled_at: '2026-10-01T08:00:00Z',
  disabled_by: 'root',
};

const BROKEN = {
  ...ACCOUNT,
  id: 'odd.other.test',
  address: null,
  local_part: null,
  resolvable: false,
};

const LIST = 'GET /api/v1/domains/example.test/accounts';

describe('the account list (A2)', () => {
  it('lists the accounts of the chosen domain with hash format, status and the unresolvable marker', async () => {
    const { double } = signedIn(SUPERUSER, { [LIST]: () => jsonResponse(200, page([ACCOUNT, LEGACY, BROKEN])) }, '/accounts?domain=example.test');

    const table = await screen.findByRole('table', { name: 'Konten' });

    expect(await within(table).findByText('anna@example.test')).toBeInTheDocument();
    expect(within(table).getByText('MD5-crypt')).toBeInTheDocument();
    expect(within(table).getByText('gesperrt')).toBeInTheDocument();
    expect(within(table).getByText('nicht auflösbar')).toBeInTheDocument();
    // The unresolvable row is shown by its stored name, not hidden (U-22).
    expect(within(table).getAllByText('odd.other.test').length).toBeGreaterThan(0);

    const query = queryOf(double.callsTo('/api/v1/domains/example.test/accounts')[0]!);

    expect(Object.fromEntries(query)).toEqual({ page: '1', per_page: '50', sort: 'name', order: 'asc' });
    expect(screen.getByRole('link', { name: 'Konto anlegen' })).toHaveAttribute('href', '/accounts/new?domain=example.test');
  });

  it('filters by password format and sorts by it on the server', async () => {
    const { double, user } = signedIn(SUPERUSER, { [LIST]: () => jsonResponse(200, page([LEGACY])) }, '/accounts?domain=example.test');

    await screen.findByText('bert@example.test');

    await user.click(screen.getByRole('combobox', { name: 'Passwortformat' }));
    await user.click(await screen.findByRole('option', { name: 'MD5-crypt' }));

    await screen.findByText('bert@example.test');
    const filtered = double.callsTo('/api/v1/domains/example.test/accounts').at(-1)!;

    expect(queryOf(filtered).get('password_format')).toBe('md5crypt');

    await user.click(screen.getByRole('button', { name: 'Passwortformat' }));
    const sorted = double.callsTo('/api/v1/domains/example.test/accounts').at(-1)!;

    expect(queryOf(sorted).get('sort')).toBe('password_format');
  });

  it('shows a failed list through the catalogue, with the request id', async () => {
    signedIn(SUPERUSER, { [LIST]: () => problemResponse(422, 'request.validation_failed') }, '/accounts?domain=example.test');

    const alert = await screen.findByRole('alert');

    expect(alert).toHaveTextContent('Vorgangsnummer: req-test');
    expect(alert).toHaveTextContent('Die Eingaben sind unvollständig oder unzulässig.');
  });

  it('asks a superuser to choose a domain and starts a domain master on their own', async () => {
    signedIn(SUPERUSER, {}, '/accounts');

    expect(await screen.findByText('Bitte eine Domain wählen.')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Konto anlegen' })).toBeNull();

    cleanup();
    const master = signedIn(DOMAIN_MASTER, { [LIST]: () => jsonResponse(200, page([ACCOUNT])) }, '/accounts');

    expect(await screen.findByText('anna@example.test')).toBeInTheDocument();
    expect(master.double.callsTo('/api/v1/domains/example.test/accounts')).toHaveLength(1);
    // A domain master's picker uses the session's domains, never GET /domains.
    expect(master.double.callsTo('/api/v1/domains')).toHaveLength(0);
  });

  it('hides the create button from a session without account.write', async () => {
    signedIn(READER, { [LIST]: () => jsonResponse(200, page([ACCOUNT])) }, '/accounts?domain=example.test');

    expect(await screen.findByText('anna@example.test')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Konto anlegen' })).toBeNull();
  });

  it('opens the account when a row is clicked', async () => {
    const { user } = signedIn(
      SUPERUSER,
      {
        [LIST]: () => jsonResponse(200, page([ACCOUNT])),
        'GET /api/v1/accounts/anna.example.test': () => jsonResponse(200, ACCOUNT, { ETag: 'W/"1"' }),
      },
      '/accounts?domain=example.test',
    );

    await user.click(await screen.findByText('anna@example.test'));

    expect(await screen.findByRole('heading', { level: 1, name: 'anna@example.test' })).toBeInTheDocument();
  });
});
