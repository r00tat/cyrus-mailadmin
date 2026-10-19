import { screen } from '@testing-library/react';
import TextField from '@mui/material/TextField';
import { useState, type FormEvent, type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ApiProblem } from '../api/problem';
import { renderWithRouter } from '../test/renderWithRouter';

import { FormActions, errorAtOrBelow, fieldProps, useFieldErrors, useRequiredCheck, type FieldErrors } from './form';
import { ProblemAlert } from './ProblemAlert';

/**
 * A small form built the way the area views are meant to build theirs: the
 * client checks required fields, the server's field errors land on the field.
 */
function Example({ serverError, onSubmit }: { serverError?: unknown; onSubmit: (name: string) => void }): ReactNode {
  const [name, setName] = useState('');
  const [quota, setQuota] = useState('');
  const [clientErrors, setClientErrors] = useState<FieldErrors>({});
  const required = useRequiredCheck();
  const fieldError = useFieldErrors(serverError, clientErrors);

  const submit = (event: FormEvent): void => {
    event.preventDefault();

    const missing = required({ 'body.name': name });

    setClientErrors(missing);

    if (Object.keys(missing).length === 0) {
      onSubmit(name);
    }
  };

  return (
    <form onSubmit={submit} noValidate>
      <TextField label="Name" required value={name} onChange={(event) => setName(event.target.value)} {...fieldProps(fieldError, 'body.name')} />
      <TextField label="Quota" value={quota} onChange={(event) => setQuota(event.target.value)} {...fieldProps(fieldError, 'body.quota_kib', 'In KiB.')} />
      <ProblemAlert error={serverError} />
      <FormActions onCancel={() => undefined} />
    </form>
  );
}

describe('form helpers', () => {
  it('checks required fields in the client with the server’s own wording', async () => {
    const onSubmit = vi.fn();
    const { user } = renderWithRouter(<Example onSubmit={onSubmit} />);

    await user.click(screen.getByRole('button', { name: 'Speichern' }));

    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByRole('textbox', { name: 'Name' })).toHaveAccessibleDescription('Pflichtangabe.');

    // Whitespace is not a value.
    await user.type(screen.getByRole('textbox', { name: 'Name' }), '   ');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));
    expect(onSubmit).not.toHaveBeenCalled();

    await user.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await user.click(screen.getByRole('button', { name: 'Speichern' }));
    expect(onSubmit).toHaveBeenCalledWith('   x');
  });

  it('puts a server field error on its field, translated, and replaces the hint while it lasts', () => {
    const error = new ApiProblem({
      status: 422,
      code: 'request.validation_failed',
      errors: { 'body.quota_kib': 'minimum' },
      requestId: 'req-1',
    });

    renderWithRouter(<Example serverError={error} onSubmit={vi.fn()} />);

    expect(screen.getByRole('textbox', { name: 'Quota' })).toHaveAccessibleDescription('Der Wert ist zu klein.');
    expect(screen.getByRole('textbox', { name: 'Quota' })).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('alert')).toHaveTextContent('Vorgangsnummer: req-1');
  });

  it('shows the hint when there is no error', () => {
    renderWithRouter(<Example onSubmit={vi.fn()} />);

    expect(screen.getByRole('textbox', { name: 'Quota' })).toHaveAccessibleDescription('In KiB.');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('falls back to a generic reason for a validator keyword it does not know', () => {
    const error = new ApiProblem({ status: 422, code: 'request.validation_failed', errors: { 'body.name': 'oneOf' } });

    renderWithRouter(<Example serverError={error} onSubmit={vi.fn()} />);

    expect(screen.getByRole('textbox', { name: 'Name' })).toHaveAccessibleDescription('Unzulässige Eingabe.');
  });

  it('disables the buttons and says so while saving', () => {
    renderWithRouter(<FormActions pending onCancel={vi.fn()} submitLabel="Anlegen" />);

    expect(screen.getByRole('button', { name: 'Wird gespeichert …' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Abbrechen' })).toBeDisabled();
  });

  it('uses the given label when idle', () => {
    renderWithRouter(<FormActions submitLabel="Anlegen" />);

    expect(screen.getByRole('button', { name: 'Anlegen' })).toHaveAttribute('type', 'submit');
    expect(screen.queryByRole('button', { name: 'Abbrechen' })).toBeNull();
  });
});

describe('errorAtOrBelow', () => {
  const errors = { 'body.name': 'Name fehlt.', 'body.members.2': 'Keine Mailadresse.' };

  it('finds an error on one entry of a list field', () => {
    expect(errorAtOrBelow(errors, 'body.members')).toBe('Keine Mailadresse.');
  });

  it('finds an error on the field itself, and nothing on a field that merely shares a prefix', () => {
    expect(errorAtOrBelow(errors, 'body.name')).toBe('Name fehlt.');
    expect(errorAtOrBelow(errors, 'body.nam')).toBeUndefined();
  });
});
