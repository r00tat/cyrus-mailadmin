import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { emptyResponse, jsonResponse, problemResponse } from '../../test/fetchDouble';
import { ACCOUNT, NO_FORWARD, NO_VACATION, READER, SUPERUSER, page, queryOf, signedIn, type Route } from '../accounts/testKit';

import type { Forwards, Vacation } from './labels';

/** Forwards and the vacation notice, as they appear on the account page. */

const BASE = '/api/v1/accounts/anna.example.test';
const ENTRY = '/accounts/anna.example.test';

const FORWARDING: Forwards = {
  ...NO_FORWARD,
  configured: true,
  destinations: ['anna@elsewhere.test'],
  keep_copy: true,
};

const FOREIGN: Vacation = {
  ...NO_VACATION,
  state: 'foreign',
  script_name: 'roundcube',
  script: 'require ["fileinto"];\nfileinto "Spam";',
};

function routes(extra: Record<string, Route> = {}): Record<string, Route> {
  return {
    [`GET ${BASE}`]: () => jsonResponse(200, ACCOUNT, { ETag: 'W/"1"' }),
    [`GET ${BASE}/quota`]: () => jsonResponse(200, { limit_kib: null, used_kib: 1024 }),
    [`GET ${BASE}/forwards`]: () => jsonResponse(200, NO_FORWARD, { ETag: 'W/"f1"' }),
    [`GET ${BASE}/vacation`]: () => jsonResponse(200, NO_VACATION, { ETag: 'W/"v1"' }),
    'GET /api/v1/domains/example.test/addresses': () => jsonResponse(200, page([])),
    ...extra,
  };
}

describe('forwards on the account page (A3)', () => {
  it('sets up a forward without a copy and warns that the vacation notice then stays silent', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`PUT ${BASE}/forwards`]: () => jsonResponse(200, { ...FORWARDING, keep_copy: false }, { ETag: 'W/"f2"' }),
      }),
      ENTRY,
    );

    expect(await screen.findByText('Keine Weiterleitung eingerichtet.')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^Ziele/), 'anna@elsewhere.test');
    await user.click(screen.getByLabelText('Kopie im Postfach behalten'));

    expect(screen.getByText(/auch die Abwesenheitsnotiz antwortet dann nicht/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Weiterleitung speichern' }));

    expect(await screen.findByText('Weiterleitung gespeichert.')).toBeInTheDocument();

    const put = double.callsTo(`${BASE}/forwards`).find((call) => call.method === 'PUT')!;

    expect(put.body).toEqual({ destinations: ['anna@elsewhere.test'], keep_copy: false, enabled: true });
    expect(put.headers.get('If-Match')).toBe('W/"f1"');
  });

  it('keeps the copy fixed where the naming scheme requires it', async () => {
    signedIn(
      SUPERUSER,
      routes({ [`GET ${BASE}/forwards`]: () => jsonResponse(200, { ...FORWARDING, copy_required: true }, { ETag: 'W/"f1"' }) }),
      ENTRY,
    );

    const keepCopy = await screen.findByLabelText('Kopie im Postfach behalten');

    expect(keepCopy).toBeChecked();
    expect(keepCopy).toBeDisabled();
    expect(screen.getByText('Bei diesem Namensschema bekommt das Postfach immer eine Kopie.')).toBeInTheDocument();
  });

  it('sends the copy the switch shows when the scheme requires one and the stored row has none', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`GET ${BASE}/forwards`]: () =>
          jsonResponse(200, { ...FORWARDING, keep_copy: false, copy_required: true }, { ETag: 'W/"f1"' }),
        [`PUT ${BASE}/forwards`]: () => jsonResponse(200, { ...FORWARDING, copy_required: true }, { ETag: 'W/"f2"' }),
      }),
      ENTRY,
    );

    expect(await screen.findByLabelText('Kopie im Postfach behalten')).toBeChecked();
    await user.type(screen.getByLabelText(/^Ziele/), '{Enter}bob@elsewhere.test');
    await user.click(screen.getByRole('button', { name: 'Weiterleitung speichern' }));

    expect(await screen.findByText('Weiterleitung gespeichert.')).toBeInTheDocument();
    expect(double.callsTo(`${BASE}/forwards`).find((call) => call.method === 'PUT')!.body).toMatchObject({ keep_copy: true });
  });

  it('removes a forward after confirmation', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`GET ${BASE}/forwards`]: () => jsonResponse(200, FORWARDING, { ETag: 'W/"f1"' }),
        [`DELETE ${BASE}/forwards`]: () => emptyResponse(204),
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Weiterleitung entfernen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Weiterleitung entfernen?' });

    await user.click(within(dialog).getByRole('button', { name: 'Weiterleitung entfernen' }));

    expect(await screen.findByText('Weiterleitung entfernt.')).toBeInTheDocument();
    expect(double.callsTo(`${BASE}/forwards`).find((call) => call.method === 'DELETE')!.headers.get('If-Match')).toBe('W/"f1"');
  });
});

describe('the vacation notice on the account page (A3)', () => {
  it('saves a notice with the default interval', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`PUT ${BASE}/vacation`]: () =>
          jsonResponse(200, { ...NO_VACATION, state: 'active', message: 'Bin weg.', interval_days: 7 }, { ETag: 'W/"v2"' }),
      }),
      ENTRY,
    );

    expect(await screen.findByText('Keine Abwesenheitsnotiz aktiv.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Abwesenheit speichern' }));
    expect(screen.getByText('Pflichtangabe.')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^Text/), 'Bin weg.');
    await user.click(screen.getByRole('button', { name: 'Abwesenheit speichern' }));

    expect(await screen.findByText('Abwesenheitsnotiz gespeichert.')).toBeInTheDocument();
    expect(double.callsTo(`${BASE}/vacation`).find((call) => call.method === 'PUT')!.body).toEqual({
      message: 'Bin weg.',
      subject: null,
      interval_days: 7,
      take_over: false,
    });
  });

  it('takes over a foreign script only after asking', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`GET ${BASE}/vacation`]: () => jsonResponse(200, FOREIGN, { ETag: 'W/"v1"' }),
        [`PUT ${BASE}/vacation`]: () =>
          jsonResponse(200, { ...NO_VACATION, state: 'active', message: 'Bin weg.', interval_days: 7 }, { ETag: 'W/"v2"' }),
      }),
      ENTRY,
    );

    expect(await screen.findByText(/fremdes Sieve-Skript \(„roundcube“\)/)).toBeInTheDocument();
    expect(screen.getByText(/fileinto "Spam"/)).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^Text/), 'Bin weg.');
    await user.click(screen.getByRole('button', { name: 'Abwesenheit speichern' }));

    const dialog = await screen.findByRole('dialog', { name: 'Fremdes Skript ersetzen?' });

    expect(double.callsTo(`${BASE}/vacation`).filter((call) => call.method === 'PUT')).toHaveLength(0);
    await user.click(within(dialog).getByRole('button', { name: 'Übernehmen' }));

    expect(await screen.findByText('Abwesenheitsnotiz gespeichert.')).toBeInTheDocument();
    expect(double.callsTo(`${BASE}/vacation`).find((call) => call.method === 'PUT')!.body).toMatchObject({ take_over: true });
  });

  it('removes a foreign script with take_over', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`GET ${BASE}/vacation`]: () => jsonResponse(200, FOREIGN, { ETag: 'W/"v1"' }),
        [`DELETE ${BASE}/vacation`]: () => emptyResponse(204),
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Abwesenheit entfernen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Abwesenheitsnotiz entfernen?' });

    expect(within(dialog).getByText(/wird deaktiviert, nicht gelöscht/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Abwesenheit entfernen' }));

    expect(await screen.findByText('Abwesenheitsnotiz entfernt.')).toBeInTheDocument();
    expect(queryOf(double.callsTo(`${BASE}/vacation`).find((call) => call.method === 'DELETE')!).get('take_over')).toBe('true');
  });

  it('shows a refusal of the sieve service through the catalogue', async () => {
    const { user } = signedIn(
      SUPERUSER,
      routes({ [`PUT ${BASE}/vacation`]: () => problemResponse(409, 'vacation.not_supported') }),
      ENTRY,
    );

    await user.type(await screen.findByLabelText(/^Text/), 'Bin weg.');
    await user.click(screen.getByRole('button', { name: 'Abwesenheit speichern' }));

    expect(await screen.findByText('Der Sieve-Dienst unterstützt keine Abwesenheitsnotizen.')).toBeInTheDocument();
  });
});

describe('forwards and vacation for a reading session', () => {
  it('shows them without anything to change', async () => {
    signedIn(
      READER,
      routes({ [`GET ${BASE}/forwards`]: () => jsonResponse(200, FORWARDING, { ETag: 'W/"f1"' }) }),
      ENTRY,
    );

    expect(await screen.findByText('Mit Kopie im Postfach.')).toBeInTheDocument();
    expect(await screen.findByText('Keine Abwesenheitsnotiz aktiv.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Weiterleitung speichern' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Weiterleitung entfernen' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Abwesenheit speichern' })).toBeNull();
  });
});
