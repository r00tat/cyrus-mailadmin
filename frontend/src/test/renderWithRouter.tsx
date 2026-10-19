import { render, type RenderResult } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { RouterProvider, createMemoryRouter } from 'react-router-dom';

import { NotificationProvider } from '../components/Notifications';

/**
 * Mounts a single component inside a memory router, for the shared building
 * blocks under `components/` that read the URL or render links.
 *
 * The full application (`renderApp`) is the right harness for a page; this is
 * for a component whose contract is narrower than a page. The router is
 * returned so a test can read where the component sent it
 * (`router.state.location.search`).
 */
export interface RenderedWithRouter extends RenderResult {
  readonly user: ReturnType<typeof userEvent.setup>;
  readonly router: ReturnType<typeof createMemoryRouter>;
}

export function renderWithRouter(
  ui: ReactNode,
  options: { initialEntry?: string; user?: Parameters<typeof userEvent.setup>[0] } = {},
): RenderedWithRouter {
  const router = createMemoryRouter(
    [{ path: '*', element: <NotificationProvider>{ui}</NotificationProvider> }],
    { initialEntries: [options.initialEntry ?? '/'] },
  );

  const result = render(<RouterProvider router={router} />);

  return { ...result, user: userEvent.setup(options.user), router };
}
