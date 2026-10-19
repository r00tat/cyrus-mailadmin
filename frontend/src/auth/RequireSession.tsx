import Box from '@mui/material/Box';
import type { ReactNode } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';

import { ErrorState, LoadingState } from '../components/StateViews';

import { useSession } from './SessionContext';

/**
 * The route guard. Convenience, not security — the backend rejects every
 * request without a valid token regardless of what the router lets through.
 *
 * The four states are kept apart deliberately:
 *
 *  - **anonymous** — no token: go to the sign-in form, remembering where the
 *    user meant to go.
 *  - **loading** — a token exists, `/auth/me` is in flight: wait. Redirecting
 *    here would throw a valid session away on every page reload.
 *  - **error** — a token exists but the server will not confirm it, and the
 *    client already established it is not a `401` (that path clears the token).
 *    Something is wrong on the server; saying so beats a sign-in form that
 *    will not work either.
 *  - **authenticated** — render.
 */
export function RequireSession(): ReactNode {
  const { status, error, refetch } = useSession();
  const location = useLocation();

  if (status === 'anonymous') {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }

  if (status === 'loading') {
    return <LoadingState />;
  }

  if (status === 'error') {
    return (
      <Box sx={{ p: 3, maxWidth: 640, mx: 'auto' }}>
        <ErrorState error={error} onRetry={refetch} />
      </Box>
    );
  }

  return <Outlet />;
}
