import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { jsonResponse, problemResponse } from '../../test/fetchDouble';

import { ACCOUNT, NO_FORWARD, NO_VACATION, READER, SUPERUSER, signedIn, type Route } from './testKit';

const DOMAIN = {
  name: 'example.test',
  account_count: 3,
  max_accounts: null,
  default_account_quota_kib: 1048576,
  domain_quota_kib: null,
  naming_scheme: 'inherited',
  effective_naming_scheme: 'dotted',
  conventional_name: true,
};

const CREATED = { ...ACCOUNT, id: 'bert.example.test', address: 'bert@example.test', local_part: 'bert' };

function routes(create: Route): Record<string, Route> {
  return {
    'GET /api/v1/domains/example.test': () => jsonResponse(200, DOMAIN, { ETag: 'W/"d"' }),
    'POST /api/v1/domains/example.test/accounts': create,
    'GET /api/v1/accounts/bert.example.test': () => jsonResponse(200, CREATED, { ETag: 'W/"1"' }),
    'GET /api/v1/accounts/bert.example.test/quota': () => jsonResponse(200, { limit_kib: 1048576, used_kib: 0 }),
    'GET /api/v1/accounts/bert.example.test/forwards': () => jsonResponse(200, NO_FORWARD, { ETag: 'W/"f"' }),
    'GET /api/v1/accounts/bert.example.test/vacation': () => jsonResponse(200, NO_VACATION, { ETag: 'W/"v"' }),
  };
}

describe('creating an account (A2)', () => {
  it('shows the user name the naming scheme builds and creates with the domain default quota', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes(() => jsonResponse(201, { account: CREATED, incomplete_steps: [] }, { ETag: 'W/"1"' })),
      '/accounts/new?domain=example.test',
    );

    expect(await screen.findByText(/name\.example\.test/)).toBeInTheDocument();
    expect(screen.getByLabelText('Vorgabe der Domain (1 GiB)')).toBeChecked();

    await user.type(screen.getByLabelText(/Name vor dem @/), 'Bert');
    expect(screen.getByText('Benutzername: bert.example.test')).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^Passwort/), 'correct horse battery');
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByRole('heading', { level: 1, name: 'bert@example.test' })).toBeInTheDocument();
    expect(await screen.findByText('Konto bert@example.test angelegt.')).toBeInTheDocument();

    const post = double.callsTo('/api/v1/domains/example.test/accounts')[0]!;

    // No quota_kib: absent means the domain's default (U-79).
    expect(post.body).toEqual({ local_part: 'Bert', password: 'correct horse battery' });
  });

  it('sends an own limit in KiB and "no limit" as null', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes(() => problemResponse(409, 'account.quota_exceeds_domain')),
      '/accounts/new?domain=example.test',
    );

    await user.type(await screen.findByLabelText(/Name vor dem @/), 'bert');
    await user.type(screen.getByLabelText(/^Passwort/), 'secret');
    await user.click(screen.getByLabelText('Begrenzt auf'));
    await user.type(screen.getByLabelText(/Größe/), '2');
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByText('Die Quota überschreitet das Kontingent der Domain.')).toBeInTheDocument();
    expect(double.callsTo('/api/v1/domains/example.test/accounts')[0]!.body).toMatchObject({ quota_kib: 2097152 });

    await user.click(screen.getByLabelText('Unbegrenzt'));
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    await screen.findByText('Die Quota überschreitet das Kontingent der Domain.');
    expect(double.callsTo('/api/v1/domains/example.test/accounts')[1]!.body).toMatchObject({ quota_kib: null });
    // Inputs survive a failure (U-74).
    expect(screen.getByLabelText(/Name vor dem @/)).toHaveValue('bert');
  });

  it('checks required fields itself and puts server field errors on their field', async () => {
    const { double, user } = signedIn(
      SUPERUSER,
      routes(() => problemResponse(422, 'request.validation_failed', { errors: { 'body.local_part': 'pattern' } })),
      '/accounts/new?domain=example.test',
    );

    await user.click(await screen.findByRole('button', { name: 'Anlegen' }));

    expect(screen.getAllByText('Pflichtangabe.')).toHaveLength(2);
    expect(double.callsTo('/api/v1/domains/example.test/accounts')).toHaveLength(0);

    await user.type(screen.getByLabelText(/Name vor dem @/), 'b b');
    await user.type(screen.getByLabelText(/^Passwort/), 'secret');
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByText('Die Eingabe hat nicht das erwartete Format.')).toBeInTheDocument();
    expect(screen.getByLabelText(/Name vor dem @/)).toHaveAttribute('aria-invalid', 'true');
  });

  it('reports a creation whose default folders are missing', async () => {
    const { user } = signedIn(
      SUPERUSER,
      routes(() => jsonResponse(201, { account: CREATED, incomplete_steps: ['default_folders'] }, { ETag: 'W/"1"' })),
      '/accounts/new?domain=example.test',
    );

    await user.type(await screen.findByLabelText(/Name vor dem @/), 'bert');
    await user.type(screen.getByLabelText(/^Passwort/), 'secret');
    await user.click(screen.getByRole('button', { name: 'Anlegen' }));

    expect(await screen.findByText(/Die Standardordner fehlen noch/)).toBeInTheDocument();
  });

  it('is not offered to a session without account.write', async () => {
    signedIn(READER, routes(() => jsonResponse(500, {})), '/accounts/new?domain=example.test');

    expect(await screen.findByText('Keine Berechtigung')).toBeInTheDocument();
  });
});
