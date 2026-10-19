import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ApiProblem } from '../api/problem';
import { renderWithRouter } from '../test/renderWithRouter';

import { ConfirmDialog } from './ConfirmDialog';

function setUp(props: Partial<Parameters<typeof ConfirmDialog>[0]> = {}) {
  const onConfirm = vi.fn();
  const onClose = vi.fn();

  const rendered = renderWithRouter(
    <ConfirmDialog
      open
      title="Domain löschen"
      message="example.test und alle Postfächer werden gelöscht."
      onConfirm={onConfirm}
      onClose={onClose}
      {...props}
    />,
  );

  return { ...rendered, onConfirm, onClose };
}

describe('ConfirmDialog', () => {
  it('asks, and confirms on click', async () => {
    const { user, onConfirm } = setUp();

    expect(screen.getByRole('dialog', { name: 'Domain löschen' })).toHaveAccessibleDescription(
      'example.test und alle Postfächer werden gelöscht.',
    );

    await user.click(screen.getByRole('button', { name: 'Bestätigen' }));

    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it('labels a destructive confirm as deleting', () => {
    setUp({ destructive: true });

    expect(screen.getByRole('button', { name: 'Löschen' })).toBeInTheDocument();
  });

  it('cancels without confirming', async () => {
    const { user, onConfirm, onClose } = setUp();

    await user.click(screen.getByRole('button', { name: 'Abbrechen' }));

    expect(onClose).toHaveBeenCalledOnce();
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it('stays disabled until the name is typed exactly — case included', async () => {
    const { user, onConfirm } = setUp({ destructive: true, typedConfirmation: 'example.test' });

    const confirm = screen.getByRole('button', { name: 'Löschen' });
    const input = screen.getByRole('textbox', { name: 'Bestätigung' });

    expect(screen.getByText('Zur Bestätigung bitte „example.test“ eingeben.')).toBeInTheDocument();
    expect(confirm).toBeDisabled();

    await user.type(input, 'Example.test');
    expect(confirm).toBeDisabled();

    // Enter in the field does not get around the check either.
    await user.keyboard('{Enter}');
    expect(onConfirm).not.toHaveBeenCalled();

    await user.clear(input);
    await user.type(input, 'example.test');
    expect(confirm).toBeEnabled();

    await user.keyboard('{Enter}');
    expect(onConfirm).toHaveBeenCalledOnce();
  });

  it('cannot be confirmed twice or cancelled while the operation runs', () => {
    setUp({ pending: true });

    expect(screen.getByRole('button', { name: 'Bestätigen' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Abbrechen' })).toBeDisabled();
  });

  it('shows a failure inside the dialog, translated from the code', () => {
    setUp({
      error: new ApiProblem({ status: 412, code: 'request.precondition_failed', requestId: 'req-7', errors: {} }),
    });

    const alert = screen.getByRole('alert');

    expect(alert).toHaveTextContent('Vorgangsnummer: req-7');
    expect(alert).not.toHaveTextContent('precondition_failed');
  });
});
