import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { createFormatters, defaultTranslator } from '../../i18n';
import { jsonResponse, problemResponse } from '../../test/fetchDouble';
import { DOMAIN_MASTER, SUPERUSER, queryOf, renderSignedIn } from '../repairs/operationsTestKit';

import { browserTimeZone, fromLocalInput, toLocalInput } from './auditTime';

/** S4 — the audit trail, through the whole application. */

const RENAME = {
  id: 501,
  recorded_at: '2026-10-17T12:05:07Z',
  actor: 'root',
  actor_role: 'superuser',
  client_ip: '192.0.2.10',
  request_id: 'req-rename',
  action: 'account.update',
  object_type: 'account',
  object_id: 'anna@example.test',
  domain: 'example.test',
  outcome: 'succeeded',
  reason: null,
  before: { name: 'Anna', password: '[redacted]' },
  after: { name: 'Anna B.', password: '[redacted]' },
  part_of: 500,
};

const REFUSAL = {
  id: 502,
  recorded_at: '2026-10-17T12:06:00Z',
  actor: 'master',
  actor_role: 'domainmaster',
  client_ip: null,
  request_id: null,
  action: 'domain.delete',
  object_type: 'domain',
  object_id: null,
  domain: null,
  outcome: 'denied',
  reason: 'missing permission domain.delete',
  before: null,
  after: null,
  part_of: null,
};

const PATH = '/api/v1/audit';

function page(items: unknown[]) {
  return { items, meta: { page: 1, per_page: 50, total: items.length } };
}

function openTrail(initialEntry = '/audit', items: unknown[] = [RENAME, REFUSAL]) {
  return renderSignedIn(SUPERUSER, initialEntry, (_call, path) => (path === PATH ? jsonResponse(200, page(items)) : undefined));
}

async function table(): Promise<HTMLElement> {
  return screen.findByRole('table', { name: 'Revisionsprotokoll' });
}

describe('the audit trail', () => {
  it('asks for the newest entries first and shows times in the browser zone, with the zone named', async () => {
    const { double } = openTrail();
    const list = await table();

    expect(await within(list).findByText('account.update')).toBeInTheDocument();
    expect(queryOf(double.callsTo(PATH)[0])).toEqual({ page: '1', per_page: '50', sort: 'recorded_at', order: 'desc' });

    const { dateTime } = createFormatters(defaultTranslator);

    expect(within(list).getByText(dateTime('2026-10-17T12:05:07Z'))).toBeInTheDocument();
    expect(screen.getByText(`Zeiten in der Zeitzone dieses Browsers (${browserTimeZone()}).`)).toBeInTheDocument();

    const refusal = within(list).getByText('domain.delete').closest('tr') as HTMLElement;

    expect(within(refusal).getByText('abgelehnt')).toBeInTheDocument();
    expect(within(refusal).getByText('Domainmaster')).toBeInTheDocument();
  });

  it('sends the filters from the URL and leaves out what the server would refuse', async () => {
    const { double } = openTrail(
      '/audit?actor=root&outcome=denied&part_of=12&since=2026-10-01T00:00:00Z&until=yesterday&request_id=req-1&sort=object&order=asc',
    );

    await table();

    expect(queryOf(double.callsTo(PATH)[0])).toEqual({
      page: '1',
      per_page: '50',
      sort: 'object',
      order: 'asc',
      actor: 'root',
      outcome: 'denied',
      part_of: '12',
      since: '2026-10-01T00:00:00Z',
      request_id: 'req-1',
    });
  });

  it('does not send an action or object type the contract pattern refuses, and says why at the field', async () => {
    const { double } = openTrail('/audit?action=Account.Create&object_type=domain.x&actor=root');

    await table();

    expect(queryOf(double.callsTo(PATH)[0])).toEqual({
      page: '1',
      per_page: '50',
      sort: 'recorded_at',
      order: 'desc',
      actor: 'root',
    });
    expect(screen.getByLabelText('Vorgang (Anfang)')).toHaveValue('Account.Create');
    expect(screen.getByLabelText('Vorgang (Anfang)')).toHaveAccessibleDescription(/etwa „account.create“/);
    expect(screen.getByLabelText('Objektart')).toHaveAccessibleDescription(/etwa „account“/);
  });

  it('drops a part_of that is not an entry id', async () => {
    const { double } = openTrail('/audit?part_of=abc&outcome=maybe');

    await table();

    expect(queryOf(double.callsTo(PATH)[0])).toEqual({ page: '1', per_page: '50', sort: 'recorded_at', order: 'desc' });
  });

  it('filters by outcome, by text and by a local time range sent in UTC', async () => {
    const { double, user } = openTrail();

    await table();

    await user.click(screen.getByRole('combobox', { name: 'Ergebnis' }));
    await user.click(await screen.findByRole('option', { name: 'gescheitert' }));

    await waitFor(() => expect(queryOf(double.callsTo(PATH).at(-1))).toMatchObject({ outcome: 'failed' }));

    await user.type(screen.getByLabelText('Vorgang (Anfang)'), 'domain.{Enter}');

    await waitFor(() => expect(queryOf(double.callsTo(PATH).at(-1))).toMatchObject({ outcome: 'failed', action: 'domain.' }));

    fireEvent.change(screen.getByLabelText('Von'), { target: { value: '2026-10-17T14:05' } });
    fireEvent.change(screen.getByLabelText('Bis'), { target: { value: '2026-10-17T15:00' } });

    const since = new Date('2026-10-17T14:05:00').toISOString().replace('.000Z', 'Z');
    const until = new Date('2026-10-17T15:00:59').toISOString().replace('.000Z', 'Z');

    await waitFor(() => expect(queryOf(double.callsTo(PATH).at(-1))).toMatchObject({ since, until }));
    expect(screen.getByLabelText('Von')).toHaveValue('2026-10-17T14:05');

    await user.click(screen.getByRole('button', { name: 'Alle Filter zurücksetzen' }));

    // Back to the first question, which is still fresh in the cache: no request needed.
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Alle Filter zurücksetzen' })).toBeNull());
    expect(screen.getByLabelText('Von')).toHaveValue('');
    expect(screen.getByLabelText('Vorgang (Anfang)')).toHaveValue('');
  });

  it('opens an entry with its redacted states and narrows the list from there', async () => {
    const { double, user } = openTrail();
    const list = await table();

    await user.click(await within(list).findByText('account.update'));

    const dialog = await screen.findByRole('dialog', { name: 'Protokolleintrag 501' });

    expect(within(dialog).getByText('192.0.2.10')).toBeInTheDocument();
    expect(within(dialog).getByText(/"name": "Anna B\."/)).toBeInTheDocument();
    expect(within(dialog).getAllByText(/\[redacted\]/)).toHaveLength(2);

    await user.click(within(dialog).getByRole('button', { name: 'Alle Einträge dieser Anfrage' }));

    await waitFor(() => expect(queryOf(double.callsTo(PATH).at(-1))).toMatchObject({ request_id: 'req-rename' }));
    expect(screen.queryByRole('dialog')).toBeNull();

    await user.click(await within(list).findByText('account.update'));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Verlauf dieses Objekts' }));

    // Replaces the filters rather than adding to them.
    await waitFor(() =>
      expect(queryOf(double.callsTo(PATH).at(-1))).toEqual({
        page: '1',
        per_page: '50',
        sort: 'recorded_at',
        order: 'desc',
        object_type: 'account',
        object_id: 'anna@example.test',
      }),
    );
  });

  it('says that a refusal had no object before or after, and offers only the links that apply', async () => {
    const { user } = openTrail();
    const list = await table();

    await user.click(await within(list).findByText('domain.delete'));

    const dialog = await screen.findByRole('dialog', { name: 'Protokolleintrag 502' });

    expect(within(dialog).getAllByText('existierte nicht')).toHaveLength(2);
    expect(within(dialog).getByText('missing permission domain.delete')).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: 'Alle Einträge dieser Anfrage' })).toBeNull();
    expect(within(dialog).queryByRole('button', { name: 'Verlauf dieses Objekts' })).toBeNull();
    expect(within(dialog).queryByRole('button', { name: 'Alle Einträge dieses Vorgangs' })).toBeNull();
  });

  it('says so when nothing matches', async () => {
    openTrail('/audit', []);

    expect(await screen.findByText('Keine Einträge für diese Auswahl.')).toBeInTheDocument();
  });

  it('renders a refused filter through the catalogue', async () => {
    renderSignedIn(SUPERUSER, '/audit?action=domain', () =>
      problemResponse(422, 'request.validation_failed', { errors: { 'query.action': 'pattern' } }),
    );

    const alert = await screen.findByRole('alert');

    expect(alert).toHaveTextContent('Die Eingaben sind unvollständig oder unzulässig.');
    expect(alert).toHaveTextContent('req-test');
  });

  it('is not shown to a domain master', async () => {
    const { double } = renderSignedIn(DOMAIN_MASTER, '/audit');

    expect(await screen.findByRole('alert')).toHaveTextContent('Keine Berechtigung');
    expect(double.callsTo(PATH)).toHaveLength(0);
  });
});

describe('the time filter conversion', () => {
  it('turns local wall-clock input into UTC and back', () => {
    const sent = fromLocalInput('2026-03-29T02:30', 'since');

    expect(sent).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/);
    expect(fromLocalInput('2026-10-17T14:05', 'until')).toMatch(/:59Z$/);
    expect(toLocalInput(fromLocalInput('2026-10-17T14:05', 'since'))).toBe('2026-10-17T14:05');
    expect(fromLocalInput('', 'since')).toBe('');
    expect(toLocalInput('not a time')).toBe('');
    expect(toLocalInput('2026-10-17T14:05:00')).toBe('');
  });
});
