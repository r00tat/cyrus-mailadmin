import type { ReactNode } from 'react';

import { ForbiddenState } from '../components/StateViews';

import { useSession } from './SessionContext';

/**
 * Renders its children only when the session holds `permission`; otherwise the
 * forbidden state.
 *
 * **Rendering only, never enforcement** (architecture 11.2, `can()` in
 * `SessionContext`). The backend checks the same permission on every request.
 * Every area route is wrapped in this so that a view reached through a typed or
 * bookmarked URL says why it is empty instead of showing a wall of `403`s.
 */
export function RequirePermission({
  permission,
  children,
}: {
  permission: string;
  children: ReactNode;
}): ReactNode {
  const { can } = useSession();

  return can(permission) ? children : <ForbiddenState />;
}
