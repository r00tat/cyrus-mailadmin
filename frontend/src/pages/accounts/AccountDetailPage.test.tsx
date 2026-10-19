import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { emptyResponse, jsonResponse, problemResponse } from '../../test/fetchDouble';

import { ACCOUNT, NO_FORWARD, NO_VACATION, READER, SUPERUSER, page, signedIn, type Route } from './testKit';

const BASE = '/api/v1/accounts/anna.example.test';

function routes(extra: Record<string, Route> = {}): Record<string, Route> {
  return {
    [`GET ${BASE}`]: () => jsonResponse(200, ACCOUNT, { ETag: 'W/"1"' }),
    [`GET ${BASE}/quota`]: () => jsonResponse(200, { limit_kib: 1048576, used_kib: 524288 }),
    [`GET ${BASE}/forwards`]: () => jsonResponse(200, NO_FORWARD, { ETag: 'W/"f"' }),
    [`GET ${BASE}/vacation`]: () => jsonResponse(200, NO_VACATION, { ETag: 'W/"v"' }),
    'GET /api/v1/domains/example.test/accounts': () => jsonResponse(200, page([])),
    ...extra,
  };
}

const ENTRY = '/accounts/anna.example.test';

describe('the account page (A2)', () => {
  it('shows the account with its live quota, formatted', async () => {
    signedIn(SUPERUSER, routes(), ENTRY);

    expect(await screen.findByRole('heading', { level: 1, name: 'anna@example.test' })).toBeInTheDocument();
    expect(await screen.findByText('512 MiB von 1 GiB belegt')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: '50 % belegt' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Passwort setzen' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Konto löschen' })).toBeInTheDocument();
  });

  it('shows a quota the mail store cannot read in the quota card only', async () => {
    signedIn(SUPERUSER, routes({ [`GET ${BASE}/quota`]: () => problemResponse(409, 'account.mailbox_missing') }), ENTRY);

    expect(await screen.findByText('Zu diesem Konto gibt es im Mailspeicher kein Postfach.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1, name: 'anna@example.test' })).toBeInTheDocument();
    expect(screen.getByLabelText('Anmeldung erlaubt')).toBeInTheDocument();
  });

  it('writes back a limit that is no whole number of MiB unchanged, under a named radio group', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`GET ${BASE}/quota`]: () => jsonResponse(200, { limit_kib: 1000001, used_kib: 1024 }),
        [`PUT ${BASE}/quota`]: () => jsonResponse(200, { limit_kib: 1000001, used_kib: 1024 }),
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Quota ändern' }));

    const dialog = await screen.findByRole('dialog', { name: 'Quota ändern' });

    expect(within(dialog).getByRole('radiogroup', { name: 'Quota' })).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Speichern' }));

    expect(await screen.findByText('Quota gespeichert.')).toBeInTheDocument();
    expect(double.callsTo(`${BASE}/quota`).find((call) => call.method === 'PUT')!.body).toEqual({ limit_kib: 1000001 });
  });

  it('asks before disabling and sends the change with the version it read', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`PATCH ${BASE}`]: () =>
          jsonResponse(200, { ...ACCOUNT, enabled: false, disabled_at: '2026-10-01T08:00:00Z', disabled_by: 'root' }, { ETag: 'W/"2"' }),
      }),
      ENTRY,
    );

    await user.click(await screen.findByLabelText('Anmeldung erlaubt'));

    const dialog = await screen.findByRole('dialog', { name: 'Konto sperren?' });

    expect(double.callsTo(BASE).filter((call) => call.method === 'PATCH')).toHaveLength(0);
    await user.click(within(dialog).getByRole('button', { name: 'Sperren' }));

    expect(await screen.findByText(/Gesperrt seit .* von root/)).toBeInTheDocument();

    const patch = double.callsTo(BASE).find((call) => call.method === 'PATCH')!;

    expect(patch.body).toEqual({ enabled: false });
    expect(patch.headers.get('If-Match')).toBe('W/"1"');
  });

  it('offers a reload when the services were changed elsewhere', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes({ [`PATCH ${BASE}`]: () => problemResponse(412, 'request.precondition_failed') }),
      ENTRY,
    );

    await user.click(await screen.findByLabelText('POP'));
    await user.click(screen.getByRole('button', { name: 'Schalter speichern' }));

    expect(await screen.findByText(/zwischenzeitlich von jemand anderem geändert/)).toBeInTheDocument();
    expect(double.callsTo(BASE).find((call) => call.method === 'PATCH')!.body).toEqual({ services: { pop: true } });
    expect(screen.getByRole('button', { name: 'Neu laden' })).toBeInTheDocument();
  });

  it('sets a password and shows a refusal from the server', async () => {
    let attempts = 0;
    const { double, user } = signedIn(
      SUPERUSER,
      routes({
        [`PUT ${BASE}/password`]: () => {
          attempts += 1;

          return attempts === 1 ? problemResponse(422, 'auth.password_too_short') : emptyResponse(204);
        },
      }),
      ENTRY,
    );

    await user.click(await screen.findByRole('button', { name: 'Passwort setzen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Passwort setzen' });

    await user.type(within(dialog).getByLabelText(/Neues Passwort/), 'short');
    await user.click(within(dialog).getByRole('button', { name: 'Passwort setzen' }));

    expect(await within(dialog).findByText('Das Passwort ist zu kurz.')).toBeInTheDocument();

    await user.clear(within(dialog).getByLabelText(/Neues Passwort/));
    await user.type(within(dialog).getByLabelText(/Neues Passwort/), 'a much longer secret');
    await user.click(within(dialog).getByRole('button', { name: 'Passwort setzen' }));

    expect(await screen.findByText('Passwort gesetzt.')).toBeInTheDocument();
    expect(double.callsTo(`${BASE}/password`).at(-1)!.body).toEqual({ password: 'a much longer secret' });
  });

  it('deletes only after the address is typed and says the catchall target goes too', async () => {
    const { double, user } = signedIn(SUPERUSER, routes({ [`DELETE ${BASE}`]: () => emptyResponse(204) }), ENTRY);

    await user.click(await screen.findByRole('button', { name: 'Konto löschen' }));

    const dialog = await screen.findByRole('dialog', { name: 'Konto löschen?' });

    expect(within(dialog).getByText(/Catchall/)).toBeInTheDocument();
    expect(await within(dialog).findByText('Im Postfach liegen derzeit 512 MiB.')).toBeInTheDocument();

    const confirm = within(dialog).getByRole('button', { name: 'Löschen' });

    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByLabelText('Bestätigung'), 'anna@example.test');
    await user.click(confirm);

    expect(await screen.findByText('Konto anna@example.test gelöscht.')).toBeInTheDocument();

    const remove = double.callsTo(BASE).find((call) => call.method === 'DELETE')!;

    expect(remove.headers.get('If-Match')).toBe('W/"1"');
  });

  it('shows a reading session no actions', async () => {
    signedIn(READER, routes(), ENTRY);

    expect(await screen.findByRole('heading', { level: 1, name: 'anna@example.test' })).toBeInTheDocument();
    expect(await screen.findByText('512 MiB von 1 GiB belegt')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Passwort setzen' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Konto löschen' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Quota ändern' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Schalter speichern' })).toBeNull();
    expect(screen.getByLabelText('Anmeldung erlaubt')).toBeDisabled();
  });
});
