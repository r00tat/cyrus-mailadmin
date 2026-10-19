import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useSyncExternalStore,
  type ReactNode,
} from 'react';

import { useApiClient } from '../api/ApiContext';
import { ApiProblem } from '../api/problem';
import type { components } from '../api/schema';

import type { TokenStore } from './tokenStore';

/**
 * Who is signed in, as the server sees them **now**.
 *
 * The identity is not read out of the token. `GET /auth/me` answers from the
 * database on every request (architecture 5.3), which is how a withdrawn role
 * or a deleted account takes effect at once instead of after up to an hour.
 * Decoding the JWT in the browser would reintroduce exactly the staleness the
 * backend went out of its way to avoid — and the frontend is not the token's
 * audience.
 */
export type CurrentSession = components['schemas']['CurrentSession'];
export type AdminRole = components['schemas']['AdminRole'];

export type SessionStatus =
  /** No token at all: show the sign-in form. */
  | 'anonymous'
  /** A token exists, `/auth/me` has not answered yet. */
  | 'loading'
  | 'authenticated'
  /** A token exists but the server will not say who it belongs to. */
  | 'error';

export interface SessionState {
  readonly status: SessionStatus;
  readonly session: CurrentSession | null;
  readonly error: unknown;
  // Properties, not methods: every caller destructures them off the context,
  // and a method may not be separated from its object.
  readonly signIn: (credentials: { username: string; password: string }) => Promise<void>;
  readonly signOut: () => Promise<void>;
  readonly refetch: () => void;
  /**
   * Permission check for **rendering only**. The server checks the same list on
   * every request; hiding a button the administrator may not use is politeness,
   * and showing one anyway gets a `403` that the UI handles. Never treat this
   * as enforcement (`CurrentSession.permissions` says so in the contract).
   */
  readonly can: (permission: string) => boolean;
}

const SessionContext = createContext<SessionState | null>(null);

export const AUTH_ME_QUERY_KEY = ['auth', 'me'] as const;

export function SessionProvider({
  tokenStore,
  children,
}: {
  tokenStore: TokenStore;
  children: ReactNode;
}): ReactNode {
  const client = useApiClient();
  const queryClient = useQueryClient();

  // A plain string snapshot, not the stored object: `useSyncExternalStore`
  // compares by identity, and a fresh object on every read would re-render
  // forever.
  const token = useSyncExternalStore(
    useCallback((listener: () => void) => tokenStore.subscribe(listener), [tokenStore]),
    useCallback(() => tokenStore.read()?.token ?? null, [tokenStore]),
    () => null,
  );

  const query = useQuery({
    queryKey: AUTH_ME_QUERY_KEY,
    queryFn: ({ signal }) => client.get('/auth/me', { signal }),
    enabled: token !== null,
    staleTime: 30_000,
    retry: (failureCount, error) => {
      // A rejected token will stay rejected; retrying only delays the sign-in
      // form. Anything transient is worth two more attempts.
      if (error instanceof ApiProblem && !error.isTransient) {
        return false;
      }

      return failureCount < 2;
    },
  });

  const signInMutation = useMutation({
    mutationFn: async (credentials: { username: string; password: string }) => {
      const session = await client.post('/auth/token', {
        body: {
          username: credentials.username,
          password: credentials.password,
          // E-15: the refresh token belongs in the HttpOnly cookie and nowhere
          // else. `body` delivery exists for curl and tests; a browser asking
          // for it would be handing a script the twelve-hour half of the pair.
          refresh_delivery: 'cookie',
        },
        withoutRefresh: true,
      });

      tokenStore.write(session.access_token, session.expires_in);

      return session;
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: AUTH_ME_QUERY_KEY });
    },
  });

  const signOutMutation = useMutation({
    mutationFn: async () => {
      try {
        // Revokes the refresh family *and* denylists the access token, and
        // expires the cookie. Both halves matter (the operation says why).
        await client.post('/auth/logout', { withoutRefresh: true });
      } finally {
        // Even when the call fails — offline, already expired — the local token
        // goes. A token left behind after a sign-out is the worst case of
        // storing it in localStorage at all (architecture 11.2).
        tokenStore.clear();
      }
    },
    onSettled: () => {
      // Not just the session: every cached answer was fetched under an identity
      // that no longer holds.
      queryClient.clear();
    },
  });

  const signIn = useCallback(
    async (credentials: { username: string; password: string }) => {
      await signInMutation.mutateAsync(credentials);
    },
    [signInMutation],
  );

  const signOut = useCallback(async () => {
    await signOutMutation.mutateAsync();
  }, [signOutMutation]);

  const refetch = useCallback(() => {
    void query.refetch();
  }, [query]);

  const value = useMemo<SessionState>(() => {
    const session = query.data ?? null;

    let status: SessionStatus = 'anonymous';

    if (token !== null) {
      if (session !== null) {
        status = 'authenticated';
      } else if (query.isError) {
        status = 'error';
      } else {
        status = 'loading';
      }
    }

    const permissions = new Set(session?.permissions ?? []);

    return {
      status,
      session,
      error: query.error,
      signIn,
      signOut,
      refetch,
      can: (permission: string) => permissions.has(permission),
    };
  }, [token, query.data, query.isError, query.error, signIn, signOut, refetch]);

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionState {
  const state = useContext(SessionContext);

  if (state === null) {
    throw new Error('useSession() was called outside of <SessionProvider>.');
  }

  return state;
}
