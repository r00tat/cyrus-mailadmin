import CssBaseline from '@mui/material/CssBaseline';
import { ThemeProvider } from '@mui/material/styles';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useMemo, type ReactNode } from 'react';

import { ApiProvider } from '../api/ApiContext';
import type { ApiClient } from '../api/client';
import { ApiProblem } from '../api/problem';
import { SessionProvider } from '../auth/SessionContext';
import type { TokenStore } from '../auth/tokenStore';
import { AppErrorBoundary } from '../components/AppErrorBoundary';
import { NotificationProvider } from '../components/Notifications';
import type { Translator } from '../i18n';
import { LocaleProvider, PreferredLocaleSync } from '../i18n/LocaleProvider';

import { createAppTheme } from './theme';

/**
 * Everything the application needs above the router, in one place, so that the
 * production entry point and a test mount the same stack.
 *
 * `LocaleProvider` decides the language (W2, U-90): the administrator's stored
 * choice once signed in, the browser's language list before that. It sits
 * above the error boundary so that a crash screen is translated too, and
 * `PreferredLocaleSync` sits below the session because it needs one. A test
 * may pass a fixed `translator` instead.
 */

export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        // Management data is `no-store` on the wire (architecture 5.7). Keeping
        // it in memory for a moment is what makes navigation feel instant; a
        // long stale time would mean showing a mailbox that was deleted two
        // screens ago.
        staleTime: 15_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          // Retrying a `401`, a `403`, a `404` or a `422` only delays the
          // message; the same request will fail the same way.
          if (error instanceof ApiProblem && !error.isTransient) {
            return false;
          }

          return failureCount < 2;
        },
      },
      mutations: {
        // Never automatically. A repeated POST is a second mailbox, and a
        // repeated DELETE is a race with whatever the user did next
        // (architecture 11.2: optimistic updates only where they are harmless).
        retry: false,
      },
    },
  });
}

export function AppProviders({
  apiClient,
  tokenStore,
  queryClient,
  translator,
  children,
}: {
  apiClient: ApiClient;
  tokenStore: TokenStore;
  queryClient?: QueryClient;
  translator?: Translator;
  children: ReactNode;
}): ReactNode {
  const theme = useMemo(() => createAppTheme(), []);
  const client = useMemo(() => queryClient ?? createAppQueryClient(), [queryClient]);

  return (
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <LocaleProvider translator={translator}>
        <AppErrorBoundary>
          <QueryClientProvider client={client}>
            <ApiProvider value={apiClient}>
              <SessionProvider tokenStore={tokenStore}>
                <PreferredLocaleSync />
                <NotificationProvider>{children}</NotificationProvider>
              </SessionProvider>
            </ApiProvider>
          </QueryClientProvider>
        </AppErrorBoundary>
      </LocaleProvider>
    </ThemeProvider>
  );
}
