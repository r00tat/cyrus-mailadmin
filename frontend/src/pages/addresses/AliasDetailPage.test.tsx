import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { emptyResponse, jsonResponse, problemResponse } from '../../test/fetchDouble';
import { READER, SUPERUSER, page, queryOf, signedIn, type Route } from '../accounts/testKit';

import type { Alias } from './labels';

const INFO: Alias = {
  address: 'info@example.test',
  domain: 'example.test',
  destinations: ['anna.example.test', 'bert@elsewhere.test'],
  enabled: true,
  sender_account: null,
  reserved: false,
  requires_confirmation: false,
  editable: true,
  entries: [],
};

const POSTMASTER: Alias = { ...INFO, address: 'postmaster@example.test', reserved: true, requires_confirmation: true };

function routes(alias: Alias, extra: Record<string, Route> = {}): Record<string, Route> {
  return {
    [`GET /api/v1/aliases/${encodeURIComponent(alias.address)}`]: () => jsonResponse(200, alias, { ETag: 'W/"a1"' }),
    'GET /api/v1/domains/example.test/addresses': () => jsonResponse(200, page([])),
    'GET /api/v1/domains/example.test/catchall': () =>
      jsonResponse(200, { domain: 'example.test', configured: false, destinations: [], enabled: true, sender_account: null, editable: true, entries: [] }, { ETag: 'W/"c"' }),
    ...extra,
  };
}

const entryOf = (alias: Alias): string => `/addresses/aliases/${encodeURIComponent(alias.address)}`;

describe('the alias page (A3)', () => {
  it('saves the destinations with the version it read', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes(INFO, {
        'PATCH /api/v1/aliases/info%40example.test': () => jsonResponse(200, { ...INFO, destinations: ['anna.example.test'] }, { ETag: 'W/"a2"' }),
      }),
      entryOf(INFO),
    );

    const field = await screen.findByLabelText(/^Ziele/);

    expect(field).toHaveValue('anna.example.test\nbert@elsewhere.test');
    await user.clear(field);
    await user.type(field, 'anna.example.test');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText('Alias gespeichert.')).toBeInTheDocument();

    const patch = double.callsTo('/api/v1/aliases/info%40example.test').find((call) => call.method === 'PATCH')!;

    expect(patch.headers.get('If-Match')).toBe('W/"a1"');
    expect(patch.body).toEqual({ destinations: ['anna.example.test'], sender_account: null, enabled: true, confirm_reserved: false });
  });

  it('offers a reload when the alias changed in the meantime', async () => {
    const { user } = signedIn(
      SUPERUSER,
      routes(INFO, { 'PATCH /api/v1/aliases/info%40example.test': () => problemResponse(412, 'request.precondition_failed') }),
      entryOf(INFO),
    );

    await screen.findByLabelText(/^Ziele/);
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText(/zwischenzeitlich von jemand anderem geändert/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Neu laden' })).toBeInTheDocument();
  });

  it('asks before disabling a reserved address and then confirms it explicitly', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes(POSTMASTER, {
        'PATCH /api/v1/aliases/postmaster%40example.test': () => jsonResponse(200, { ...POSTMASTER, enabled: false }, { ETag: 'W/"a2"' }),
      }),
      entryOf(POSTMASTER),
    );

    await user.click(await screen.findByLabelText('Aktiv'));
    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    const dialog = await screen.findByRole('dialog', { name: 'Reservierte Adresse deaktivieren?' });

    expect(double.callsTo('/api/v1/aliases/postmaster%40example.test').filter((call) => call.method === 'PATCH')).toHaveLength(0);
    await user.click(within(dialog).getByRole('button', { name: 'Trotzdem deaktivieren' }));

    expect(await screen.findByText('Alias gespeichert.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/aliases/postmaster%40example.test').find((call) => call.method === 'PATCH')!.body).toMatchObject({
      enabled: false,
      confirm_reserved: true,
    });
  });

  it('deletes a reserved alias with the warning shown and confirm_reserved sent', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes(POSTMASTER, { 'DELETE /api/v1/aliases/postmaster%40example.test': () => emptyResponse(204) }),
      entryOf(POSTMASTER),
    );

    await user.click(await screen.findByRole('button', { name: 'Alias löschen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Alias löschen?' });

    expect(within(dialog).getByText(/reservierte Adresse \(RFC 2142\)/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Trotzdem entfernen' }));

    expect(await screen.findByText('Alias postmaster@example.test gelöscht.')).toBeInTheDocument();

    const remove = double.callsTo('/api/v1/aliases/postmaster%40example.test').find((call) => call.method === 'DELETE')!;

    expect(queryOf(remove).get('confirm_reserved')).toBe('true');
    expect(remove.headers.get('If-Match')).toBe('W/"a1"');
  });

  it('shows rows that cannot be edited together instead of a form', async () => {
    const mixed: Alias = {
      ...INFO,
      editable: false,
      entries: [
        { id: 'r1', type: 'alias', alias: 'info@example.test', destinations: ['anna.example.test'], enabled: true, username: '', sender_account: null, reserved: false, unknown_reason: null },
        { id: 'r2', type: 'alias', alias: 'info@example.test', destinations: ['bert@elsewhere.test'], enabled: false, username: '', sender_account: null, reserved: false, unknown_reason: null },
      ],
    };

    signedIn(SUPERUSER, routes(mixed), entryOf(mixed));

    expect(await screen.findByText(/lassen sich nicht gemeinsam bearbeiten/)).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Gespeicherte Zeilen' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Speichern' })).toBeNull();
  });

  it('shows a reading session the alias without form or delete', async () => {
    signedIn(READER, routes(INFO), entryOf(INFO));

    expect(await screen.findByText('Ziele: anna.example.test, bert@elsewhere.test')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Speichern' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Alias löschen' })).toBeNull();
  });
});
