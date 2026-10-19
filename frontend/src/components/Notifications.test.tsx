import Button from '@mui/material/Button';
import { render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { renderWithRouter } from '../test/renderWithRouter';

import { useNotify } from './Notifications';

function Sender(): ReactNode {
  const notify = useNotify();

  return <Button onClick={() => notify('Domain angelegt.')}>Senden</Button>;
}

describe('notifications', () => {
  it('confirm a change as a status message that can be closed by name', async () => {
    const { user } = renderWithRouter(<Sender />);

    await user.click(screen.getByRole('button', { name: 'Senden' }));

    expect(await screen.findByRole('status')).toHaveTextContent('Domain angelegt.');

    await user.click(screen.getByRole('button', { name: 'Schließen' }));

    await waitFor(() => {
      expect(screen.queryByText('Domain angelegt.')).toBeNull();
    });
  });

  it('refuse to work outside the provider rather than drop the message', () => {
    // React reports the thrown render on the console; that is the expected outcome here.
    vi.spyOn(console, 'error').mockImplementation(() => undefined);

    expect(() => render(<Sender />)).toThrow(/NotificationProvider/);
  });
});
