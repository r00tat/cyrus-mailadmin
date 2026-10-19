import { screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ApiProblem } from '../api/problem';
import { renderWithRouter } from '../test/renderWithRouter';

import { ProblemAlert } from './ProblemAlert';

describe('ProblemAlert', () => {
  it('renders nothing without an error', () => {
    const { container } = renderWithRouter(<ProblemAlert error={null} />);

    expect(container).toBeEmptyDOMElement();
  });

  it('translates the code through the catalogue and shows the request id', () => {
    renderWithRouter(
      <ProblemAlert error={new ApiProblem({ status: 0, code: 'client.network', requestId: 'req-9', errors: {} })} />,
    );

    const alert = screen.getByRole('alert');

    expect(alert).toHaveTextContent('Der Server ist nicht erreichbar. Bitte die Verbindung prüfen.');
    expect(alert).toHaveTextContent('Vorgangsnummer: req-9');
  });

  it('falls back to the response text for a code this build does not know', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);

    renderWithRouter(
      <ProblemAlert
        error={new ApiProblem({
          status: 409,
          code: 'domain.from_the_future' as never,
          detail: 'Something new happened.',
          errors: {},
        })}
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Something new happened.');
  });

  it('never shows the message of a plain exception', () => {
    renderWithRouter(<ProblemAlert error={new Error('TypeError at line 12 with token abc')} />);

    expect(screen.getByRole('alert')).toHaveTextContent('Es ist ein unbekannter Fehler aufgetreten.');
    expect(screen.getByRole('alert')).not.toHaveTextContent('token');
  });

  it('offers a reload for a version conflict, and only for that', async () => {
    const onReload = vi.fn();
    const { user, rerender } = renderWithRouter(
      <ProblemAlert
        error={new ApiProblem({ status: 412, code: 'request.precondition_failed', errors: {} })}
        onReload={onReload}
      />,
    );

    await user.click(within(screen.getByRole('alert')).getByRole('button', { name: 'Neu laden' }));
    expect(onReload).toHaveBeenCalledOnce();

    rerender(
      <ProblemAlert error={new ApiProblem({ status: 422, code: 'request.validation_failed', errors: {} })} onReload={onReload} />,
    );

    expect(within(screen.getByRole('alert')).queryByRole('button')).toBeNull();
  });
});
