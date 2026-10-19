import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { emptyResponse, jsonResponse, problemResponse } from '../../test/fetchDouble';
import { READER, SUPERUSER, page, queryOf, signedIn, type Route } from '../accounts/testKit';

import type { AddressEntry, Alias, Catchall } from './labels';

const row = (overrides: Partial<AddressEntry> & Pick<AddressEntry, 'id' | 'type' | 'alias'>): AddressEntry => ({
  destinations: ['anna.example.test'],
  enabled: true,
  username: '',
  sender_account: null,
  reserved: false,
  unknown_reason: null,
  ...overrides,
});

const OWN = row({ id: 'r1', type: 'mailbox_address', alias: 'anna@example.test', username: 'anna.example.test', sender_account: 'anna.example.test' });
const ALIAS = row({ id: 'r2', type: 'alias', alias: 'info@example.test' });
const POSTMASTER = row({ id: 'r3', type: 'alias', alias: 'postmaster@example.test', reserved: true });
const CATCHALL_ROW = row({ id: 'r4', type: 'catchall', alias: '@example.test' });
const FORWARD = row({ id: 'r5', type: 'forward', alias: 'bert.example.test', destinations: ['bert@elsewhere.test'] });
const ODD = row({ id: 'r6', type: 'unknown', alias: 'lost@example.test', destinations: [], unknown_reason: 'no_destination' });

const NO_CATCHALL: Catchall = {
  domain: 'example.test',
  configured: false,
  destinations: [],
  enabled: true,
  sender_account: null,
  editable: true,
  entries: [],
};

const LIST = '/api/v1/domains/example.test/addresses';
const ENTRY = '/addresses?domain=example.test';

function routes(extra: Record<string, Route> = {}): Record<string, Route> {
  return {
    [`GET ${LIST}`]: () => jsonResponse(200, page([OWN, ALIAS, POSTMASTER, CATCHALL_ROW, FORWARD, ODD])),
    'GET /api/v1/domains/example.test/catchall': () => jsonResponse(200, NO_CATCHALL, { ETag: 'W/"c1"' }),
    ...extra,
  };
}

describe('the address list (A3)', () => {
  it('shows every row with its type, and the rows that fit no pattern with the reason', async () => {
    const { double } = signedIn(SUPERUSER, routes(), ENTRY);

    const table = await screen.findByRole('table', { name: 'Adressen' });

    expect(await within(table).findByText('Kontoadresse')).toBeInTheDocument();
    expect(within(table).getAllByText('Alias')).toHaveLength(2);
    expect(within(table).getByText('Catchall')).toBeInTheDocument();
    expect(within(table).getByText('Weiterleitung')).toBeInTheDocument();
    expect(within(table).getByText('Unbekannt')).toBeInTheDocument();
    expect(within(table).getByText('Die Zeile hat kein Ziel.')).toBeInTheDocument();
    expect(within(table).getByText('reserviert')).toBeInTheDocument();

    expect(within(table).getByRole('link', { name: 'info@example.test' })).toHaveAttribute('href', '/addresses/aliases/info%40example.test');
    expect(within(table).getByRole('link', { name: 'bert.example.test' })).toHaveAttribute('href', '/accounts/bert.example.test');

    // An account's own address goes with the account; it has no remove button.
    expect(within(table).queryByRole('button', { name: 'Zeile anna@example.test entfernen' })).toBeNull();
    expect(within(table).getByRole('button', { name: 'Zeile lost@example.test entfernen' })).toBeInTheDocument();

    expect(await screen.findByText('Diese Domain hat keinen Catchall.')).toBeInTheDocument();
    expect(Object.fromEntries(queryOf(double.callsTo(LIST)[0]!))).toEqual({ page: '1', per_page: '50', sort: 'alias', order: 'asc' });
  });

  it('filters by type on the server', async () => {
    const { double, user } = signedIn(SUPERUSER, routes(), ENTRY);

    await screen.findByText('info@example.test');
    await user.click(screen.getByRole('combobox', { name: 'Art' }));
    await user.click(await screen.findByRole('option', { name: 'Unbekannt' }));

    await screen.findByText('info@example.test');
    expect(queryOf(double.callsTo(LIST).at(-1)!).get('type')).toBe('unknown');
  });

  it('removes a reserved row only after asking again, then with confirm_reserved', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        'DELETE /api/v1/domains/example.test/addresses/r3': (call) =>
          queryOf(call).get('confirm_reserved') === 'true' ? emptyResponse(204) : problemResponse(409, 'address.reserved'),
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Zeile postmaster@example.test entfernen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Zeile entfernen?' });

    await user.click(within(dialog).getByRole('button', { name: 'Löschen' }));

    expect(await within(dialog).findByText(/reservierte Adresse \(RFC 2142\)/)).toBeInTheDocument();
    expect(queryOf(double.callsTo('/api/v1/domains/example.test/addresses/r3')[0]!).has('confirm_reserved')).toBe(false);

    await user.click(within(dialog).getByRole('button', { name: 'Trotzdem entfernen' }));

    expect(await screen.findByText('Zeile postmaster@example.test entfernt.')).toBeInTheDocument();
    expect(queryOf(double.callsTo('/api/v1/domains/example.test/addresses/r3')[1]!).get('confirm_reserved')).toBe('true');
  });

  it('creates an alias and opens it', async () => {
    const created: Alias = {
      address: 'sales@example.test',
      domain: 'example.test',
      destinations: ['anna.example.test'],
      enabled: true,
      sender_account: 'anna.example.test',
      reserved: false,
      requires_confirmation: false,
      editable: true,
      entries: [],
    };
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        'POST /api/v1/domains/example.test/aliases': () => jsonResponse(201, created, { ETag: 'W/"a1"' }),
        'GET /api/v1/aliases/sales%40example.test': () => jsonResponse(200, created, { ETag: 'W/"a1"' }),
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Alias anlegen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Alias anlegen' });

    await user.type(within(dialog).getByLabelText(/Name vor dem @/), 'sales');
    await user.type(within(dialog).getByLabelText(/^Ziele/), 'anna.example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'sales@example.test' })).toBeInTheDocument();
    expect(double.callsTo('/api/v1/domains/example.test/aliases')[0]!.body).toEqual({
      local_part: 'sales',
      destinations: ['anna.example.test'],
      enabled: true,
    });
  });

  it('sets up the catchall with the version it read', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        'PUT /api/v1/domains/example.test/catchall': (call) =>
          jsonResponse(200, { ...NO_CATCHALL, configured: true, destinations: (call.body as Catchall).destinations }, { ETag: 'W/"c2"' }),
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Catchall einrichten' }));

    const dialog = await screen.findByRole('dialog', { name: 'Catchall für example.test' });

    await user.type(within(dialog).getByLabelText(/^Ziele/), 'anna.example.test');
    await user.click(within(dialog).getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText('Catchall gespeichert.')).toBeInTheDocument();

    const put = double.callsTo('/api/v1/domains/example.test/catchall').find((call) => call.method === 'PUT')!;

    expect(put.headers.get('If-Match')).toBe('W/"c1"');
    expect(put.body).toMatchObject({ destinations: ['anna.example.test'], enabled: true });
  });

  it('offers a reading session neither create nor remove', async () => {
    signedIn(READER, routes(), ENTRY);

    expect(await screen.findByText('info@example.test')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Alias anlegen' })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Zeile .* entfernen$/ })).toBeNull();
    expect(await screen.findByText('Diese Domain hat keinen Catchall.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Catchall einrichten' })).toBeNull();
  });
});
