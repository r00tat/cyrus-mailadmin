import { render, type RenderResult } from '@testing-library/react';
import type { QueryClient } from '@tanstack/react-query';
import userEvent from '@testing-library/user-event';
import { RouterProvider, createMemoryRouter } from 'react-router-dom';

import { ApiClient } from '../api/client';
import { AppProviders, createAppQueryClient } from '../app/AppProviders';
import { routes } from '../app/routes';
import { createTokenStore, type TokenStore } from '../auth/tokenStore';

import { createFetchDouble, type FetchDouble, type Responder } from './fetchDouble';

/**
 * Mounts the whole application — the real providers, the real route table, the
 * real client — against a scripted `fetch`.
 *
 * The one thing that is not real is the network. Everything else is the code
 * that ships, which is what makes "sign in, navigate, sign out, and no token is
 * left behind" a statement about the application rather than about the test's
 * own wiring (U-75: this stands in for a browser end-to-end run).
 */
export interface RenderedApp extends RenderResult {
  readonly user: ReturnType<typeof userEvent.setup>;
  readonly tokenStore: TokenStore;
  readonly double: FetchDouble;
  /** The application's query cache, for tests that check what a change invalidates. */
  readonly queryClient: QueryClient;
}

export function renderApp(
  responder: Responder,
  options: { initialEntries?: string[] } = {},
): RenderedApp {
  const tokenStore = createTokenStore();
  const double = createFetchDouble(responder);

  const apiClient = new ApiClient({
    baseUrl: '/api/v1',
    tokenStore,
    fetch: double.fetch,
  });

  const router = createMemoryRouter(routes, {
    initialEntries: options.initialEntries ?? ['/'],
  });

  const queryClient = createAppQueryClient();

  const result = render(
    <AppProviders apiClient={apiClient} tokenStore={tokenStore} queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>,
  );

  return { ...result, user: userEvent.setup(), tokenStore, double, queryClient };
}

export function pathOf(url: string): string {
  return new URL(url, 'http://test.invalid').pathname;
}
